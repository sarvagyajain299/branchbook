<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Maintenance\DispatchDueReminders;
use Illuminate\Console\Command;

class SendRemindersCommand extends Command
{
    protected $signature = 'branchbook:send-reminders';

    protected $description = 'Queue reminders for confirmed appointments starting within 24 hours';

    public function handle(DispatchDueReminders $action): int
    {
        $this->info("Queued {$action->handle()} reminder(s).");

        return self::SUCCESS;
    }
}
