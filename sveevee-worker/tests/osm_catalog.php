<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';
require dirname(__DIR__, 2).'/backend/vendor/autoload.php';

use App\Support\CatalogTopics;
use Sveevee\Worker\Research\OpenStreetMap\CategoryMapper;

$allowed = CatalogTopics::keysForScope(CatalogTopics::SCOPE_BUSINESS_PAGES);
$mapping = (new ReflectionClass(CategoryMapper::class))->getConstant('MAP');
foreach ($mapping as $source => $key) {
    if (! in_array($key, $allowed, true)) {
        throw new RuntimeException('OSM category '.$source.' maps outside the actual business-page catalog: '.$key);
    }
}
echo 'OSM category map: '.count($mapping)." mappings validated against the backend business-page catalog.\n";
