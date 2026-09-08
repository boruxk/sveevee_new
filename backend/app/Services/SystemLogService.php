<?php

namespace App\Services;

use App\Exceptions\SystemLogConflictException;
use App\Models\SystemLogEntry;
use DateTimeInterface;
use Illuminate\Support\Str;
use JsonException;

class SystemLogService
{
    /**
     * @return array{entry: SystemLogEntry, replayed: bool}
     *
     * @throws JsonException
     */
    public function record(
        string $source,
        string $type,
        string $externalId,
        string $status,
        array $data,
        DateTimeInterface|string $occurredAt,
        ?string $actorType = null,
        ?string $actorIdentifier = null,
    ): array {
        $payloadHash = $this->payloadHash($data);
        $entry = SystemLogEntry::query()->firstOrCreate([
            'source' => $source,
            'type' => $type,
            'external_id' => $externalId,
        ], [
            'uuid' => (string) Str::uuid(),
            'status' => $status,
            'actor_type' => $actorType,
            'actor_identifier' => $actorIdentifier,
            'payload_hash' => $payloadHash,
            'data' => $data,
            'occurred_at' => $occurredAt,
        ]);

        if (! $entry->wasRecentlyCreated && ! hash_equals($entry->payload_hash, $payloadHash)) {
            throw new SystemLogConflictException(
                'This run_id was already logged with a different report.'
            );
        }

        return [
            'entry' => $entry,
            'replayed' => ! $entry->wasRecentlyCreated,
        ];
    }

    /** @throws JsonException */
    private function payloadHash(array $data): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($data),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
