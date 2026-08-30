<?php

namespace App\Observers;

use App\Models\Page;
use App\Services\PageIdentityService;

class PageObserver
{
    public function saved(Page $page): void
    {
        app(PageIdentityService::class)->sync($page);
    }
}
