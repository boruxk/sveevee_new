<?php

namespace Tests\Unit;

use App\Support\CatalogTopics;
use PHPUnit\Framework\TestCase;

class ImportCategoryCurationTest extends TestCase
{
    public function test_every_observed_source_category_has_an_explicit_consistent_decision(): void
    {
        $root = dirname(__DIR__, 2);
        $aliases = require $root.'/config/import_category_aliases.php';
        $audit = json_decode(file_get_contents($root.'/resources/data/import-category-curation-2026-09-16.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2287, $audit['observation_count']);
        $this->assertCount($audit['observation_count'], $audit['decisions']);
        $businessKeys = array_fill_keys(CatalogTopics::keysForScope(CatalogTopics::SCOPE_BUSINESS_PAGES), true);
        $seen = [];
        $counts = ['mapped' => 0, 'excluded' => 0, 'pending' => 0];
        foreach ($audit['decisions'] as $decision) {
            $identity = $decision['provider'].'|'.$decision['source_key'];
            $this->assertArrayNotHasKey($identity, $seen, 'Repeated source decision: '.$identity);
            $seen[$identity] = true;
            $this->assertArrayHasKey($decision['status'], $counts);
            $counts[$decision['status']]++;
            $this->assertNotSame('', trim($decision['reason']));
            $this->assertNotSame('', trim($decision['source_label']));
            if ($decision['status'] === 'mapped') {
                $this->assertArrayHasKey($decision['category_key'], $businessKeys, 'Not a selectable business category: '.$identity);
                $this->assertSame($decision['category_key'], $aliases[$decision['provider']][$decision['source_key']] ?? null);
                unset($aliases[$decision['provider']][$decision['source_key']]);
            } else {
                $this->assertNull($decision['category_key']);
                $this->assertArrayNotHasKey($decision['source_key'], $aliases[$decision['provider']] ?? [], 'Uncertain category must not become an automatic alias.');
            }
        }
        $this->assertSame($audit['counts'], $counts);
        $this->assertSame(0, array_sum(array_map('count', $aliases)), 'Every production alias must have an audited decision.');
    }

    public function test_new_topics_are_business_categories_in_their_reviewed_groups_with_unique_routes(): void
    {
        $audit = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/data/import-category-curation-2026-09-16.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(88, $audit['new_business_categories']);
        foreach ($audit['new_business_categories'] as $expected) {
            $topic = CatalogTopics::findByKey($expected['key']);
            $this->assertNotNull($topic);
            $this->assertSame($expected['group_key'], $topic['group_key']);
            $this->assertContains(CatalogTopics::SCOPE_BUSINESS_PAGES, $topic['scopes']);
            foreach (['he', 'en', 'ru', 'fr'] as $locale) {
                $this->assertNotSame('', trim($topic['labels'][$locale]));
            }
        }
        $slugs = CatalogTopics::all()->pluck('slug')->all();
        $this->assertCount(count($slugs), array_unique($slugs), 'New catalog pages must not collide with existing routes.');
    }

    public function test_source_synonyms_and_non_business_labels_are_not_blindly_added_as_categories(): void
    {
        $aliases = require dirname(__DIR__, 2).'/config/import_category_aliases.php';
        $this->assertSame('food_catering.desserts_ice_cream', $aliases['overture_places']['ice_cream_shop']);
        $this->assertSame('food_catering.desserts_ice_cream', $aliases['foursquare_places']['4bf58dd8d48988d1c9941735']);
        $this->assertSame('education_courses.schools', $aliases['overture_places']['public_school']);
        $this->assertSame('food_catering.fish_stores', $aliases['overture_places']['fishmonger']);
        $this->assertSame('food_catering.fish_stores', $aliases['overture_places']['seafood_market']);
        $this->assertSame('food_catering.fish_stores', $aliases['foursquare_places']['4bf58dd8d48988d10e951735']);
        $this->assertSame('health_care.clinics_doctors', $aliases['foursquare_places']['63be6904847c3692a84b9bd3']);
        $this->assertSame('food_catering.greengrocers', $aliases['data_gov_ckan']['license_description:cd0181d2b38186ff46918f4034a81db2871a3bb4c85c70fc3992e1de5bfdc343']);
        $this->assertArrayNotHasKey('geographic_entities', $aliases['overture_places']);
        $this->assertArrayNotHasKey('corporate_or_business_office', $aliases['overture_places']);
        $this->assertArrayNotHasKey('530e33ccbcbc57f1066bbff7', $aliases['foursquare_places']);
        $this->assertArrayNotHasKey('license_description:23a717cb681482d97c0d14ffaddb59dd1c02c60ceb16b78440fdf6ee60607f6b', $aliases['data_gov_ckan']);
    }
}
