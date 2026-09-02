<?php

namespace App\Console\Commands;

use App\Services\BounceService;
use Illuminate\Console\Command;

class IngestMailBounce extends Command
{
    protected $signature = 'mail:ingest-bounce {token}';

    protected $description = 'Process a delivery status notification from Postfix';

    public function handle(BounceService $bounces): int
    {
        $rawMessage = stream_get_contents(STDIN, 2_000_000) ?: '';
        $bounces->ingest((string) $this->argument('token'), $rawMessage);

        return self::SUCCESS;
    }
}
