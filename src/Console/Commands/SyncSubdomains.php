<?php

declare(strict_types=1);

namespace Simplify\Subdomains\Console\Commands;

use Illuminate\Console\Command;
use Simplify\Subdomains\Subdomains;

final class SyncSubdomains extends Command
{
    protected $signature = 'simplify-subdomains:sync';

    protected $description = 'Retry subdomains that are not connected, follow port changes and remove records of deleted servers.';

    public function handle(): int
    {
        Subdomains::tick();

        return self::SUCCESS;
    }
}
