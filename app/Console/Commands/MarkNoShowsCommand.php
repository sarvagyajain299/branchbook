<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Maintenance\MarkNoShows;
use Illuminate\Console\Command;

class MarkNoShowsCommand extends Command
{
    protected $signature = 'branchbook:mark-no-shows';

    protected $description = 'Mark confirmed appointments that nobody checked in for as no-shows';

    public function handle(MarkNoShows $action): int
    {
        $this->info("Marked {$action->handle()} no-show(s).");

        return self::SUCCESS;
    }
}
