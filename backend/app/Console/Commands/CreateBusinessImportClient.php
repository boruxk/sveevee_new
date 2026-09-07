<?php

namespace App\Console\Commands;

use App\Models\BusinessImportClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;

class CreateBusinessImportClient extends Command
{
    protected $signature = 'business-import:client
        {name : A recognizable name for the external worker}
        {--read-only : Allow search and duplicate checks, but no writes}';

    protected $description = 'Create an OAuth client dedicated to the business import API';

    public function handle(ClientRepository $clients): int
    {
        $name = trim((string) $this->argument('name'));
        if ($name === '' || mb_strlen($name) > 255) {
            $this->error('The client name must contain between 1 and 255 characters.');

            return self::FAILURE;
        }

        $scopes = [BusinessImportClient::SCOPE_READ];
        if (! $this->option('read-only')) {
            $scopes[] = BusinessImportClient::SCOPE_WRITE;
        }

        $client = DB::transaction(function () use ($clients, $name, $scopes) {
            $client = $clients->createClientCredentialsGrantClient($name);

            BusinessImportClient::query()->create([
                'oauth_client_id' => $client->getKey(),
                'name' => $name,
                'allowed_scopes' => $scopes,
                'active' => true,
            ]);

            return $client;
        });

        $this->info('Business import OAuth client created.');
        $this->table(['Field', 'Value'], [
            ['Client ID', $client->getKey()],
            ['Client secret', $client->plainSecret],
            ['Allowed scopes', implode(' ', $scopes)],
            ['Token URL', rtrim((string) config('app.url'), '/').'/oauth/token'],
        ]);
        $this->warn('Store the client secret now. It cannot be displayed again.');

        return self::SUCCESS;
    }
}
