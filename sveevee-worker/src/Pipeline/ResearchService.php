<?php

declare(strict_types=1);

namespace Sveevee\Worker\Pipeline;

use Sveevee\Worker\Config\ResearchTarget;
use Sveevee\Worker\Domain\BusinessNormalizer;
use Sveevee\Worker\Domain\IncompleteCandidateException;
use Sveevee\Worker\Reporting\RunReport;
use Sveevee\Worker\Research\BusinessEnricherInterface;
use Sveevee\Worker\Research\SourceAdapterInterface;
use Sveevee\Worker\Storage\IdentityConflictException;
use Sveevee\Worker\Storage\WorkerRepository;
use Sveevee\Worker\Support\Logger;
use Sveevee\Worker\Support\SourceFingerprint;

final class ResearchService
{
    /**
     * @param SourceAdapterInterface[] $sources
     * @param BusinessEnricherInterface[] $enrichers
     * @param ResearchTarget[] $targets
     */
    public function __construct(
        private readonly array $sources,
        private readonly array $enrichers,
        private readonly array $targets,
        private readonly BusinessNormalizer $normalizer,
        private readonly WorkerRepository $repository,
        private readonly Logger $logger,
        private readonly array $quotas = [],
    ) {}

    public function research(string $runId, int $limit, RunReport $report): void
    {
        $remaining = max(1, $limit);
        $categoryCounts = [];
        $neighborhoodCounts = [];

        $targetCount = count($this->targets);
        foreach ($this->targets as $targetIndex => $target) {
            if ($remaining <= 0 || ! $this->targetHasCapacity($target, $categoryCounts, $neighborhoodCounts)) {
                continue;
            }
            $targetsLeft = max(1, $targetCount - $targetIndex);
            $targetRemaining = min($remaining, (int) ceil($remaining / $targetsLeft));
            foreach ($this->sources as $source) {
                if ($remaining <= 0 || $targetRemaining <= 0) {
                    break;
                }
                $report->source($source->name(), 0);
                try {
                    foreach ($source->research($target, $targetRemaining) as $raw) {
                        if ($remaining <= 0 || $targetRemaining <= 0
                            || ! $this->targetHasCapacity($target, $categoryCounts, $neighborhoodCounts)) {
                            break;
                        }
                        $remaining--;
                        $targetRemaining--;
                        $categoryCounts[$target->categoryKey] = ($categoryCounts[$target->categoryKey] ?? 0) + 1;
                        if ($target->neighborhood !== null) {
                            $key = $target->city.'|'.$target->neighborhood;
                            $neighborhoodCounts[$key] = ($neighborhoodCounts[$key] ?? 0) + 1;
                        }
                        $report->increment('found');
                        $report->source($source->name());
                        $sourceUrl = $this->sourceUrl($raw);
                        if ($sourceUrl !== null
                            && ! $this->repository->shouldProcessUrl(
                                $source->name(),
                                $sourceUrl,
                                $source->refreshAfterDays(),
                                SourceFingerprint::hash($raw),
                            )) {
                            $report->increment('duplicates');
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
                        } catch (IncompleteCandidateException $exception) {
                            $report->increment('incomplete');
                            $report->error('normalization', $exception->getMessage(), ['source_url' => $sourceUrl]);
                            $this->repository->recordResearchFailure(
                                $runId, $source->name(), $sourceUrl, $raw,
                                'incomplete', $exception->getMessage()
                            );
                        } catch (IdentityConflictException $exception) {
                            $report->increment('failed');
                            $report->error('identity', $exception->getMessage(), [
                                'source_url' => $sourceUrl,
                                'business_ids' => $exception->businessIds,
                            ]);
                            $this->repository->recordResearchFailure(
                                $runId, $source->name(), $sourceUrl, $raw,
                                'identity_conflict', $exception->getMessage()
                            );
                        } catch (\Throwable $exception) {
                            $report->increment('failed');
                            $report->error('research_item', $exception->getMessage(), ['source_url' => $sourceUrl]);
                            $this->repository->recordResearchFailure(
                                $runId, $source->name(), $sourceUrl, $raw,
                                'research_error', $exception->getMessage()
                            );
                        }
                    }
                } catch (\Throwable $exception) {
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
                }
            }
        }
    }

    private function targetHasCapacity(ResearchTarget $target, array $categoryCounts, array $neighborhoodCounts): bool
    {
        $categoryLimit = $this->nullableLimit($this->quotas['per_category'] ?? null);
        if ($categoryLimit !== null && ($categoryCounts[$target->categoryKey] ?? 0) >= $categoryLimit) {
            return false;
        }
        $neighborhoodLimit = $this->nullableLimit($this->quotas['per_neighborhood'] ?? null);
        if ($neighborhoodLimit !== null && $target->neighborhood !== null) {
            $key = $target->city.'|'.$target->neighborhood;
            if (($neighborhoodCounts[$key] ?? 0) >= $neighborhoodLimit) {
                return false;
            }
        }

        return true;
    }

    private function nullableLimit(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function sourceUrl(array $raw): ?string
    {
        $value = trim((string) ($raw['source_url'] ?? ''));

        return filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }
}
