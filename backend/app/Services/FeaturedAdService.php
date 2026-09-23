<?php

namespace App\Services;

use App\Models\Ad;
use Illuminate\Database\Eloquent\Builder;

class FeaturedAdService
{
    public function __construct(private readonly BusinessProEntitlementService $entitlements) {}

    /** Only currently public ads with a verified, current paid entitlement qualify. */
    public function query(): Builder
    {
        return $this->entitlements->applyFeaturedAdEntitlement(
            Ad::query()->select('ads.id')->where('ads.is_featured', true)->active()
                ->where(fn (Builder $type) => $type
                    ->where(fn (Builder $private) => $private->where('ads.type', Ad::TYPE_PRIVATE)->whereNull('ads.page_id'))
                    ->orWhere(fn (Builder $business) => $business->where('ads.type', Ad::TYPE_BUSINESS)->whereNotNull('ads.page_id')))
                ->whereHas('user', fn (Builder $user) => $user->whereNull('banned_at'))
        );
    }

    public function expression(string $table = 'ads'): array
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/iD', $table)) {
            throw new \InvalidArgumentException('Invalid ad table alias.');
        }
        $query = $this->query()->toBase();

        return ['case when '.$table.'.id in ('.$query->toSql().') then 1 else 0 end', $query->getBindings()];
    }

    public function isFeatured(Ad $ad): bool
    {
        if (! $ad->is_featured || ! $ad->isVisible() || $ad->community_hidden_at) {
            return false;
        }
        if (array_key_exists('featured_active', $ad->getAttributes())) {
            return (bool) $ad->getAttribute('featured_active');
        }

        return $this->query()->whereKey($ad->id)->exists();
    }

    /** Detect promotion changes during cursor pagination without a growing cursor. */
    public function fingerprint(): string
    {
        $query = $this->query()->toBase();
        $connection = $query->getConnection();
        if ($connection->getDriverName() === 'sqlite') {
            // SQLite is also used locally/in tests. Keep the same 128-bit set
            // digest without moving every eligible ID across the DB boundary.
            $connection->getReadPdo()->sqliteCreateAggregate('featured_id_digest',
                static fn (?string $digest, int $rows, int $id): string => ($digest ?? str_repeat("\0", 16))
                    ^ substr(hash('sha256', (string) $id, true), 0, 16),
                static fn (?string $digest, int $rows): string => bin2hex($digest ?? str_repeat("\0", 16)),
                1);
            $digestSql = 'featured_id_digest(ads.id)';
        } else {
            // SHA-256 IDs, XORed as two independent 64-bit halves, preserve
            // sensitivity to ID replacements even when their count is equal.
            // Only the aggregate row is transferred; no GROUP_CONCAT truncation.
            $halves = array_map(static fn (int $start): string => "lpad(hex(bit_xor(cast(conv(substring(sha2(cast(ads.id as char), 256), {$start}, 16), 16, 10) as unsigned))), 16, '0')",
                [1, 17]);
            $digestSql = 'lower(concat('.implode(', ', $halves).'))';
        }
        $row = $query->reorder()->select([])->selectRaw('count(*) as item_count, '.$digestSql.' as id_digest')->first();

        return hash('sha256', $row->item_count.':'.$row->id_digest);
    }
}
