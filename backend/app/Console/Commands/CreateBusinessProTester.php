<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateBusinessProTester extends Command
{
    protected $signature = 'business-pro:create-tester
        {--email=pro@sveevee.local : Dedicated test account email}
        {--password= : Initial password; required outside the local/testing environment}
        {--allow-production : Explicitly allow creating the dedicated account outside local/testing}';

    protected $description = 'Create the dedicated private Business Pro preview account without granting paid access';

    public function handle(): int
    {
        $local = app()->environment(['local', 'testing']);
        if (! $local && (! $this->option('allow-production') || ! $this->option('password'))) {
            $this->error('Outside local/testing, --allow-production and an explicit --password are required.');

            return self::FAILURE;
        }
        $email = strtolower(trim((string) $this->option('email')));
        $password = (string) ($this->option('password') ?: 'password');
        $validator = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:'.($local ? 8 : 16)],
        ]);
        if ($validator->fails()) {
            $this->error(implode(' ', $validator->errors()->all()));

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();
        if ($existing) {
            if (! $existing->business_pro_tester || ! $existing->hasRole('user') || $existing->banned_at !== null) {
                $this->error('That email belongs to an existing account that is not an active dedicated tester. Nothing changed.');

                return self::FAILURE;
            }
            $this->info('The dedicated Business Pro tester already exists. Password, role and profile were kept unchanged.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($email, $password): void {
            $user = new User;
            $user->forceFill([
                'name' => 'Business Pro Test', 'given_name' => 'Business Pro', 'family_name' => 'Test',
                'email' => $email, 'password' => $password, 'locale' => 'he', 'role' => 'user',
                'email_verified_at' => now(), 'business_pro_tester' => true, 'consented' => true,
            ])->save();
            $user->profile()->update(['city' => 'Jerusalem', 'email_chat_notifications' => false]);
        });
        $this->info("Dedicated Business Pro tester created: {$email}");
        $this->line('No business page, paid subscription, charge or email was created.');

        return self::SUCCESS;
    }
}
