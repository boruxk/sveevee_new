<?php

namespace App\Services;

use App\Models\Page;
use App\Support\PublicImageVariants;
use Illuminate\Support\Arr;

/** Apply a validated page form without replacing ownership or import provenance. */
class PageFormDataService
{
    public const FIELDS = [
        'name', 'public_description', 'contact_email', 'phone', 'address',
        'category_key', 'palette_key', 'setup',
        'logo_path', 'logo_original_name', 'banner_path', 'banner_original_name',
    ];

    /** Fill only; caller saves in its transaction and deletes returned files after commit. */
    public function fill(Page $page, array $data): array
    {
        $data = Arr::only($data, self::FIELDS);
        if (array_key_exists('setup', $data)) {
            $setup = is_array($data['setup']) ? $data['setup'] : [];
            foreach (array_keys($setup) as $key) {
                if (str_starts_with($key, 'imported_')) {
                    unset($setup[$key]);
                }
            }
            // These belong to the imported source, not to the editable page form.
            foreach (['imported_attributions', 'imported_categories'] as $key) {
                if (array_key_exists($key, $page->setup ?? [])) {
                    $setup[$key] = $page->setup[$key];
                }
            }
            $data['setup'] = $setup;
        }

        $obsolete = [];
        foreach (['logo_path', 'banner_path'] as $field) {
            if (array_key_exists($field, $data) && $page->{$field} && $page->{$field} !== $data[$field]) {
                $obsolete[] = $page->{$field};
                array_push($obsolete, ...PublicImageVariants::variantPaths($page->{$field}));
            }
        }
        $page->fill($data);

        return array_values(array_unique($obsolete));
    }
}
