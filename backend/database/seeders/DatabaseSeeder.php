<?php

namespace Database\Seeders;

use App\Models\Ad;
use App\Models\Page;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@sveevee.local'],
            [
                'name' => 'SVEEVEE Admin',
                'given_name' => 'SVEEVEE',
                'family_name' => 'Admin',
                'password' => 'password',
                'locale' => 'he',
                'role' => 'admin',
            ]
        );

        $user = User::query()->updateOrCreate(
            ['email' => 'user@sveevee.local'],
            [
                'name' => 'Miriam Neighbor',
                'given_name' => 'Miriam',
                'family_name' => 'Neighbor',
                'password' => 'password',
                'locale' => 'he',
                'role' => 'user',
            ]
        );

        $admin->profile()->updateOrCreate([], [
            'phone' => '+972 50 000 0000',
            'city' => 'Jerusalem',
            'neighborhood' => 'Ramot',
        ]);

        $user->profile()->updateOrCreate([], [
            'phone' => '+972 50 123 4567',
            'city' => 'Jerusalem',
            'neighborhood' => 'Ramot',
        ]);

        Ad::query()
            ->where('user_id', $user->id)
            ->whereIn('type', ['business_ad', 'community_ad'])
            ->delete();
        Page::query()->where('user_id', $user->id)->delete();

        Ad::query()->updateOrCreate(
            ['user_id' => $user->id, 'title' => 'Kids chair to give away'],
            [
                'page_id' => null,
                'type' => 'private_ad',
                'text' => 'Stable kids chair, pickup in Ramot.',
                'expires_at' => now()->addWeek(),
                'city' => 'Jerusalem',
                'neighborhood' => 'Ramot',
            ]
        );
    }
}
