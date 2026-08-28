<?php

namespace Tests\Unit;

use App\Support\CatalogTopics;
use App\Support\CatalogTopicTranslations;
use PHPUnit\Framework\TestCase;

class CatalogLocalizationTest extends TestCase
{
    public function test_every_catalog_group_and_topic_has_complete_localization(): void
    {
        $catalogItems = [];

        foreach (CatalogTopics::groups() as $group) {
            $catalogItems[$group['key']] = $group['labels'];

            foreach ($group['topics'] as $topic) {
                $catalogItems[$topic['key']] = $topic['labels'];
            }
        }

        $runtimeKeys = array_keys($catalogItems);
        $translationKeys = CatalogTopicTranslations::keys();
        sort($runtimeKeys);
        sort($translationKeys);

        $this->assertSame($runtimeKeys, $translationKeys);

        foreach ($catalogItems as $key => $labels) {
            foreach (['he', 'en', 'ru', 'fr'] as $locale) {
                $this->assertArrayHasKey($locale, $labels, "Missing {$locale} label for {$key}");
                $this->assertIsString($labels[$locale]);
                $this->assertNotSame('', trim($labels[$locale]), "Empty {$locale} label for {$key}");
                $this->assertStringNotContainsString("\u{FFFD}", $labels[$locale], "Invalid Unicode in {$locale} label for {$key}");
                $this->assertSame(CatalogTopicTranslations::label($key, $locale), $labels[$locale]);
            }
        }
    }
}
