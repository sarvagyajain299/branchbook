<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Maintenance\ExpireStaleHolds;
use Illuminate\Console\Command;

class ExpireHoldsCommand extends Command
{
    protected $signature = 'branchbook:expire-holds';

    protected $description = 'Release unverified holds whose 10 minutes have passed';

    public function handle(ExpireStaleHolds $action): int
    {
        $this->info("Expired {$action->handle()} hold(s).");

        return self::SUCCESS;
    }
}
