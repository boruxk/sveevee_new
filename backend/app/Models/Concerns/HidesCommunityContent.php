<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait HidesCommunityContent
{
    protected static function bootHidesCommunityContent(): void
    {
        static::addGlobalScope('community_visibility', fn (Builder $query) => $query
            ->whereNull($query->getModel()->qualifyColumn('community_hidden_at')));
    }
}
