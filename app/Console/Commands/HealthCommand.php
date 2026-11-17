<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthCommand extends Command
{
    protected $signature = 'branchbook:health';

    protected $description = 'Check the database, queue, failed jobs, scheduler heartbeat and mail config';

    public function handle(): int
    {
        $checks = [
            ['Database', ...$this->database()],
            ['Queue backlog', ...$this->queueBacklog()],
            ['Failed jobs (24 h)', ...$this->failedJobs()],
            ['Scheduler heartbeat', ...$this->heartbeat()],
            ['Mail configuration', ...$this->mail()],
        ];

        $this->table(['Check', 'Status', 'Detail'], $checks);

        $failed = collect($checks)->contains(fn (array $c): bool => $c[1] === 'FAIL');
        $this->line($failed ? 'Unhealthy.' : 'Healthy.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{string, string} */
    private function database(): array
    {
        try {
            DB::select('select 1');

            return ['OK', 'connected to '.DB::getDatabaseName()];
        } catch (Throwable $e) {
            return ['FAIL', 'cannot connect: '.$e::class];
        }
    }

    /** @return array{string, string} */
    private function queueBacklog(): array
    {
        try {
            $count = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('created_at');
            $age = $oldest === null ? 0 : time() - (int) $oldest;

            return $age > 600 ? ['FAIL', "{$count} job(s); oldest waiting {$age}s: is the queue worker running?"] : ['OK', "{$count} job(s) waiting"];
        } catch (Throwable) {
            return ['WARN', 'jobs table not available (queue driver is not "database")'];
        }
    }

    /** @return array{string, string} */
    private function failedJobs(): array
    {
        try {
            $count = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

            return $count > 0 ? ['WARN', "{$count} failed job(s): run queue:failed"] : ['OK', 'none'];
        } catch (Throwable) {
            return ['WARN', 'failed_jobs table not available'];
        }
    }

    /** @return array{string, string} */
    private function heartbeat(): array
    {
        $last = Cache::get('scheduler:heartbeat');

        if ($last === null) {
            return ['FAIL', 'never ran: is `schedule:run` (cron) or `schedule:work` running?'];
        }

        $age = time() - (int) $last;

        return $age > 300 ? ['FAIL', "last ran {$age}s ago"] : ['OK', "last ran {$age}s ago"];
    }

    /** @return array{string, string} */
    private function mail(): array
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        if ($from === '') {
            return ['FAIL', 'MAIL_FROM_ADDRESS is empty'];
        }

        if (in_array($mailer, ['log', 'array'], true) && app()->isProduction()) {
            return ['FAIL', "mailer \"{$mailer}\" would silently drop customer emails in production"];
        }

        return ['OK', "mailer \"{$mailer}\", from {$from}"];
    }
}
