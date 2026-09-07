<?php

namespace App\Console\Commands;

use App\Models\BusinessImportClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

class RevokeBusinessImportClient extends Command
{
    protected $signature = 'business-import:revoke-client {client_id}';

    protected $description = 'Disable a business import OAuth client and revoke all of its access tokens';

    public function handle(): int
    {
        $clientId = (string) $this->argument('client_id');
        $registration = BusinessImportClient::query()->find($clientId);

        if (! $registration) {
            $this->error('Business import client not found.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($clientId, $registration): void {
            $registration->update(['active' => false]);
            Passport::client()->newQuery()->whereKey($clientId)->update(['revoked' => true]);
            Passport::token()->newQuery()->where('client_id', $clientId)->update(['revoked' => true]);
        });

        $this->info('Business import client and its access tokens were revoked.');

        return self::SUCCESS;
    }
}
