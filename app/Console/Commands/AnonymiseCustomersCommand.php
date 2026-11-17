<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Maintenance\AnonymiseInactiveCustomers;
use Illuminate\Console\Command;

class AnonymiseCustomersCommand extends Command
{
    protected $signature = 'branchbook:anonymise-customers {--dry-run : Show how many customers would be anonymised, change nothing} {--months=24 : Months of inactivity}';

    protected $description = 'Remove name, email and phone of customers inactive for 24 months (keeps their appointments for statistics)';

    public function handle(AnonymiseInactiveCustomers $action): int
    {
        $months = max(1, (int) $this->option('months'));
        $dryRun = (bool) $this->option('dry-run');

        $count = $action->handle($months, $dryRun);

        $this->info($dryRun
            ? "Dry run: {$count} customer(s) inactive for {$months}+ months would be anonymised."
            : "Anonymised {$count} customer(s) inactive for {$months}+ months.");

        return self::SUCCESS;
    }
}
