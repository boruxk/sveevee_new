<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Support\CatalogTopics;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class SystemSettingsService
{
    public const CACHE_KEY = 'sveevee.system-settings.v1';

    public const SECTIONS = ['ads', 'labels', 'chat', 'moderation', 'platform'];

    public function defaults(): array
    {
        return [
            'ads' => [
                'visibility_days' => 7,
                'private_active_limit' => 10,
                'page_active_limit' => 30,
                'purge_after_expiry_days' => 30,
            ],
            'labels' => [
                'new_days' => 3,
                'popular_views' => 100,
                'popular_contacts' => 10,
                'highly_rated_average' => 4.7,
                'highly_rated_min_ratings' => 3,
            ],
            'chat' => [
                'new_recipients_per_day' => 10,
                'messages_per_minute' => 30,
            ],
            'moderation' => [
                'products_per_business_page' => 100,
                'future_events_per_community_page' => 50,
            ],
            'platform' => [
                'maintenance_enabled' => false,
                'maintenance_messages' => [
                    'he' => 'Sveevee נמצאת כעת בתחזוקה. נסו שוב בקרוב.',
                    'en' => 'Sveevee is currently undergoing maintenance. Please try again soon.',
                    'ru' => 'На Sveevee сейчас проводятся технические работы. Пожалуйста, повторите попытку позже.',
                    'fr' => 'Sveevee est actuellement en maintenance. Veuillez reessayer prochainement.',
                ],
                'popular_topic_keys' => CatalogTopics::POPULAR_KEYS,
            ],
        ];
    }

    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $settings = $this->defaults();

            if (! Schema::hasTable('system_settings')) {
                return $settings;
            }

            SystemSetting::query()->get(['key', 'value'])->each(function (SystemSetting $setting) use (&$settings): void {
                if (! in_array($setting->key, self::SECTIONS, true) || ! is_array($setting->value)) {
                    return;
                }

                $merged = array_replace_recursive(
                    $settings[$setting->key],
                    $setting->value
                );

                if ($setting->key === 'platform' && array_key_exists('popular_topic_keys', $setting->value)) {
                    $merged['popular_topic_keys'] = array_values($setting->value['popular_topic_keys']);
                }

                $settings[$setting->key] = $merged;
            });

            return $settings;
        });
    }

    public function section(string $section): array
    {
        if (! in_array($section, self::SECTIONS, true)) {
            throw new InvalidArgumentException("Unknown settings section: {$section}");
        }

        return $this->all()[$section];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default);
    }

    public function integer(string $key, int $default): int
    {
        return (int) $this->get($key, $default);
    }

    public function updateSection(string $section, array $values, ?int $adminId): array
    {
        if (! in_array($section, self::SECTIONS, true)) {
            throw new InvalidArgumentException("Unknown settings section: {$section}");
        }

        $value = array_replace_recursive($this->defaults()[$section], $values);

        if ($section === 'platform' && array_key_exists('popular_topic_keys', $values)) {
            $value['popular_topic_keys'] = array_values($values['popular_topic_keys']);
        }

        DB::transaction(function () use ($section, $value, $adminId): void {
            SystemSetting::query()->updateOrCreate(
                ['key' => $section],
                ['value' => $value, 'updated_by_user_id' => $adminId]
            );
        });

        $this->clearCache();

        return $this->section($section);
    }

    public function popularTopicKeys(): array
    {
        $keys = $this->get('platform.popular_topic_keys', CatalogTopics::POPULAR_KEYS);

        if (! is_array($keys)) {
            return CatalogTopics::POPULAR_KEYS;
        }

        return collect($keys)
            ->filter(fn (mixed $key): bool => is_string($key) && CatalogTopics::findByKey($key) !== null)
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    public function maintenanceStatus(): array
    {
        return [
            'enabled' => (bool) $this->get('platform.maintenance_enabled', false),
            'messages' => $this->get('platform.maintenance_messages', $this->defaults()['platform']['maintenance_messages']),
        ];
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
