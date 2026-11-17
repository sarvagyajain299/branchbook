<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use App\Services\Assignment\AdvisorAssignmentStrategy;
use App\Services\Assignment\LeastBookedTodayStrategy;
use App\Services\Assignment\RandomStrategy;
use App\Services\Reminders\LogChannel;
use App\Services\Reminders\ReminderChannel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Reminders go through the ReminderChannel interface. LogChannel only logs; bind a real
        // SMS/WhatsApp class here to go live (see ReminderChannel's docblock).
        $this->app->bind(ReminderChannel::class, LogChannel::class);

        // Dependency injection in action: anything that type-hints AdvisorAssignmentStrategy
        // receives whichever implementation config chooses. Swap it with ASSIGNMENT_STRATEGY.
        $this->app->bind(AdvisorAssignmentStrategy::class, fn (): AdvisorAssignmentStrategy => match (config('branchbook.assignment_strategy')) {
            'random' => new RandomStrategy,
            default => new LeastBookedTodayStrategy,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Outside production, make Eloquent fail loudly instead of silently:
        // - lazy loading a relation throws (catches N+1 queries in tests),
        // - assigning a non-fillable attribute throws (catches mass-assignment mistakes),
        // - reading an attribute that was not selected throws.
        // In production we keep the forgiving defaults so a missed eager load
        // degrades performance instead of crashing a customer's booking.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Every date Laravel creates (now(), model casts, request dates) becomes
        // a CarbonImmutable. Mutable dates are a classic slot-calculation bug:
        // $start->addMinutes(30) would silently change $start as well.
        Date::use(CarbonImmutable::class);

        // Reports: HQ and branch managers only (managers are confined to their own branch in ReportRequest).
        Gate::define('view-reports', fn (User $user): bool => in_array($user->role, [Role::HqAdmin, Role::BranchManager], true));

        // In production, refuse `migrate:fresh`, `migrate:refresh`, `migrate:reset`
        // and `db:wipe`. They drop every table; typed into the wrong terminal on
        // the live server they would erase every customer booking.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
