<?php

declare(strict_types=1);

namespace Sveevee\Worker\Pipeline;

use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\IncompleteCandidateException;
use Sveevee\Worker\Http\SourceRequestBudgetExceeded;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Research\BusinessEnricherInterface;
use Sveevee\Worker\Research\CursorSourceInterface;
use Sveevee\Worker\Research\OverturePlacesSource;
use Sveevee\Worker\Research\SourceAdapterInterface;
use Sveevee\Worker\Storage\IdentityConflictException;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\SourceFingerprint;

final class ResearchService
{
    private const GUARDED_SOURCES = ['data_gov_ckan', 'tel_aviv_business_licenses'];

    private array $pausedSources = [];

    private array $deferredTargets = [];

    private bool $governmentBudgetExhausted = false;

    /**
     * @param  SourceAdapterInterface[]  $sources
     * @param  BusinessEnricherInterface[]  $enrichers
     * @param  ResearchTarget[]  $targets
     */
    public function __construct(
        private readonly array $sources,
        private readonly array $enrichers,
        private readonly array $targets,
        private readonly BusinessNormalizer $normalizer,
        private readonly WorkerRepository $repository,
        private readonly Logger $logger,
        private readonly int $businessesPerCombination = 100,
        private readonly int $productiveTargetLimit = 10,
    ) {}

    /** Standalone research caps eligible pending rows; run uses candidates() lazily. */
    public function research(string $runId, int $limit, RunReport $report): void
    {
        $remaining = max(1, $limit);
        $productive = 0;
        $seen = [];
        foreach ($this->targets as $target) {
            if ($remaining === 0 || $productive >= max(1, $this->productiveTargetLimit) || ! $this->canResearch()) {
                break;
            }
            $foundBefore = $report->metric('found');
            $accepted = 0;
            foreach ($this->candidates($runId, $target, $report) as $business) {
                if (isset($seen[$business['id']])) {
                    continue;
                }
                $seen[$business['id']] = true;
                $accepted++;
                $remaining--;
                if ($remaining === 0 || $accepted >= max(1, $this->businessesPerCombination)) {
                    break;
                }
            }
            if ($accepted > 0) {
                $productive++;
            }
            if (! $report->dryRun && ! $this->isTargetDeferred($target)) {
                $this->repository->markResearchTargetCompleted($target, $runId);
            }
            $report->target($target->key(), $target->city, $target->categoryKey, $report->metric('found') - $foundBefore);
        }
    }

    /**
     * Sources bound their own finite dataset; rejected candidates do not use a write slot.
     * The importer stops this generator only when actual successful writes fill the budget.
     */
    public function candidates(string $runId, ResearchTarget $target, RunReport $report): iterable
    {
        foreach ($this->sources as $source) {
            if ($this->sourcePaused($source)) {
                $this->deferTarget($target, $report);

                continue;
            }
            $report->source($source->name(), 0);
            try {
                foreach ($source->research($target, PHP_INT_MAX) as $raw) {
                    $report->increment('found');
                    $report->source($source->name());
                    $sourceUrl = $this->sourceUrl($raw);
                    if ($sourceUrl !== null
                        && ! $this->repository->shouldProcessUrl(
                            $source->name(),
                            $sourceUrl,
                            $source->refreshAfterDays(),
                            SourceFingerprint::hash($raw),
                            reconsiderLegacySource: $target->isFullSource(),
                        )) {
                        $report->increment('duplicates');
                        if ($source instanceof CursorSourceInterface) {
                            $source->acknowledge($raw);
                        }

                        continue;
                    }

                    try {
                        $candidate = $this->normalizer->normalize($raw, $target, $source->name());
                        foreach ($this->enrichers as $enricher) {
                            if (! $enricher->supports($candidate)) {
                                continue;
                            }
                            $website = (string) ($candidate->data['website'] ?? '');
                            if (! $this->repository->shouldProcessUrl($enricher->name(), $website, $enricher->refreshAfterDays())) {
                                continue;
                            }
                            try {
                                $candidate = $enricher->enrich($candidate);
                                $report->source($enricher->name());
                            } catch (\Throwable $exception) {
                                $this->repository->recordUrlFailure($enricher->name(), $website, $exception->getMessage());
                                $report->increment('failed');
                                $report->error('enrichment', $exception->getMessage(), [
                                    'source' => $enricher->name(),
                                    'url' => $website,
                                ]);
                                $this->logger->warning('Website enrichment failed; base business was retained.', [
                                    'url' => $website,
                                    'error' => $exception->getMessage(),
                                ]);
                            }
                        }

                        $stored = $this->repository->upsertCandidate($candidate);
                        $report->increment($stored['is_new'] ? 'new' : 'duplicates');
                        $business = $this->repository->business($stored['business_id']);
                        if ($source instanceof CursorSourceInterface) {
                            $source->acknowledge($raw);
                        }
                        if ($business['status'] === 'pending'
                            && ($target->isFullSource() || (($business['payload']['address']['city'] ?? null) === $target->city
                            && ($business['payload']['category_key'] ?? null) === $target->categoryKey))) {
                            yield $business;
                        }
                    } catch (IncompleteCandidateException $exception) {
                        $report->increment('incomplete');
                        $report->error('normalization', $exception->getMessage(), ['source_url' => $sourceUrl]);
                        $this->repository->recordResearchFailure(
                            $runId, $source->name(), $sourceUrl, $raw,
                            'incomplete', $exception->getMessage(), false
                        );
                    } catch (IdentityConflictException $exception) {
                        $report->increment('failed');
                        $report->error('identity', $exception->getMessage(), [
                            'source_url' => $sourceUrl,
                            'business_ids' => $exception->businessIds,
                        ]);
                        $this->repository->recordResearchFailure(
                            $runId, $source->name(), $sourceUrl, $raw,
                            'identity_conflict', $exception->getMessage(), false
                        );
                    } catch (\Throwable $exception) {
                        $report->increment('failed');
                        $report->error('research_item', $exception->getMessage(), ['source_url' => $sourceUrl]);
                        $this->repository->recordResearchFailure(
                            $runId, $source->name(), $sourceUrl, $raw,
                            'research_error', $exception->getMessage()
                        );
                        if ($source instanceof CursorSourceInterface && $target->isFullSource()) {
                            // No pending business may exist yet. Keep the cursor before this row
                            // so a transient storage/normalization failure can be retried next run.
                            throw $exception;
                        }
                    }
                    if ($source instanceof CursorSourceInterface) {
                        $source->acknowledge($raw);
                    }
                }
            } catch (SourceRequestBudgetExceeded $exception) {
                $this->governmentBudgetExhausted = true;
                $this->deferTarget($target, $report);
                $this->logger->info($exception->getMessage(), ['target' => $target->key()]);
            } catch (\Throwable $exception) {
                if (in_array($source->name(), self::GUARDED_SOURCES, true)) {
                    $this->pausedSources[$source->name()] = true;
                    $report->increment('source_errors');
                    $this->deferTarget($target, $report);
                }
                $report->increment('failed');
                $report->error('source', $exception->getMessage(), [
                    'source' => $source->name(),
                    'target' => $target->key(),
                ]);
                $this->logger->error('Source adapter failed.', [
                    'source' => $source->name(),
                    'target' => $target->key(),
                    'error' => $exception->getMessage(),
                ]);
                if ($source instanceof OverturePlacesSource && $target->isOvertureAll()) {
                    throw $exception;
                }
            } finally {
                if ($source instanceof OverturePlacesSource) {
                    $report->overtureProgress($source->progress());
                }
            }
        }
    }

    public function canResearch(): bool
    {
        foreach ($this->sources as $source) {
            if (! $this->sourcePaused($source)) {
                return true;
            }
        }

        return false;
    }

    public function reportProgress(RunReport $report): void
    {
        foreach ($this->sources as $source) {
            if ($source instanceof OverturePlacesSource) {
                $report->overtureProgress($source->progress());
            }
        }
    }

    public function isTargetDeferred(ResearchTarget $target): bool
    {
        return isset($this->deferredTargets[$target->key()]);
    }

    public function deferPausedSources(ResearchTarget $target, RunReport $report): void
    {
        foreach ($this->sources as $source) {
            if ($this->sourcePaused($source)) {
                $this->deferTarget($target, $report);

                return;
            }
        }
    }

    private function sourcePaused(SourceAdapterInterface $source): bool
    {
        return isset($this->pausedSources[$source->name()])
            || ($this->governmentBudgetExhausted && in_array($source->name(), self::GUARDED_SOURCES, true));
    }

    private function deferTarget(ResearchTarget $target, RunReport $report): void
    {
        $this->deferredTargets[$target->key()] = true;
        $report->deferTarget($target->key(), $target->city, $target->categoryKey);
    }

    private function sourceUrl(array $raw): ?string
    {
        $value = trim((string) ($raw['source_url'] ?? ''));

        return filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }
}
