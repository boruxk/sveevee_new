<?php

namespace App\Observers;

use App\Jobs\NotifyCommunitySubscribers;
use App\Models\Ad;
use App\Models\LocalQuestion;
use App\Models\PageEvent;
use Illuminate\Database\Eloquent\Model;

class CommunityActivityObserver
{
    public function created(Model $model): void
    {
        $type = match (true) {
            $model instanceof LocalQuestion => 'question', $model instanceof Ad => 'ad', $model instanceof PageEvent => 'event'
        };
        NotifyCommunitySubscribers::dispatch($type, $model->id)->afterCommit();
    }
}
