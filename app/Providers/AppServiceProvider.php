<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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

        // In production, refuse `migrate:fresh`, `migrate:refresh`, `migrate:reset`
        // and `db:wipe`. They drop every table; typed into the wrong terminal on
        // the live server they would erase every customer booking.
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }
}
