<?php

declare(strict_types=1);

/*
 * One worker for scripts/concurrency-check.sh. Waits until a start time (so several workers
 * fire at the same instant), then performs ONE queue action against the real database.
 *
 *   php scripts/concurrency-check.php issue  BRANCH_CODE SERVICE_CODE START_EPOCH
 *   php scripts/concurrency-check.php call   ADVISOR_EMAIL START_EPOCH
 *   php scripts/concurrency-check.php book   BRANCH_CODE SERVICE_CODE LOCAL_DATETIME START_EPOCH N
 *
 * Prints the result on one line. Development tool: it writes to whatever database .env points at.
 */

use App\Actions\Booking\HoldAppointment;
use App\Actions\Queue\CallNextTicket;
use App\Actions\Queue\IssueQueueTicket;
use App\Data\BookingRequestData;
use App\Exceptions\SlotUnavailable;
use App\Models\Branch;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$wait = static function (string $epoch): void {
    while (microtime(true) < (float) $epoch) {
        usleep(200);
    }
};

try {
    switch ($argv[1] ?? '') {
        case 'issue':
            $branch = Branch::query()->where('code', $argv[2])->firstOrFail();
            $service = Service::query()->where('code', $argv[3])->firstOrFail();
            $wait($argv[4]);
            echo app(IssueQueueTicket::class)->handle($branch, $service)->display_code;
            break;

        case 'call':
            $user = User::query()->where('email', $argv[2])->firstOrFail();
            $wait($argv[3]);
            $ticket = app(CallNextTicket::class)->handle($user);
            echo $ticket === null ? 'none' : (string) $ticket->id;
            break;

        case 'book':
            $branch = Branch::query()->where('code', $argv[2])->firstOrFail();
            $service = Service::query()->where('code', $argv[3])->firstOrFail();
            $startsAt = CarbonImmutable::parse($argv[4], 'Asia/Dubai')->utc();
            $wait($argv[5]);
            try {
                $appointment = app(HoldAppointment::class)->handle(new BookingRequestData(
                    $service, $branch, $startsAt, 'Load', 'Tester', "load{$argv[6]}@example.com", '+971501234567',
                ));
                echo 'booked:'.$appointment->advisor_id;
            } catch (SlotUnavailable) {
                echo 'taken';
            }
            break;

        default:
            echo 'usage: see the header of this file';
    }
} catch (Throwable $e) {
    echo 'ERR:'.get_class($e).':'.$e->getMessage();
}
echo PHP_EOL;
