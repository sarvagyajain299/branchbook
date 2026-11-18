<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AppointmentCrudController;
use App\Http\Controllers\Admin\CustomerCrudController;
use Illuminate\Support\Facades\Route;

// Admin panel routes (Backpack). The group adds the /admin prefix, the web session and the
// CheckIfAdmin middleware, so only logged-in HQ administrators and branch managers get in.
Route::group([
    'prefix' => config('backpack.base.route_prefix', 'admin'),
    'middleware' => array_merge(
        (array) config('backpack.base.web_middleware', 'web'),
        (array) config('backpack.base.middleware_key', 'admin')
    ),
    'namespace' => 'App\Http\Controllers\Admin',
], function (): void {
    Route::crud('branch', 'BranchCrudController');
    Route::crud('branch-hour', 'BranchHourCrudController');
    Route::crud('closure', 'ClosureCrudController');
    Route::crud('service', 'ServiceCrudController');
    Route::crud('advisor', 'AdvisorCrudController');
    Route::crud('advisor-shift', 'AdvisorShiftCrudController');
    Route::crud('advisor-time-off', 'AdvisorTimeOffCrudController');
    Route::crud('user', 'UserCrudController');
    Route::crud('audit-log', 'AuditLogCrudController');
    Route::crud('appointment', 'AppointmentCrudController');
    Route::crud('customer', 'CustomerCrudController');

    // Actions that are not plain CRUD.
    Route::post('appointment/{id}/cancel', [AppointmentCrudController::class, 'cancel'])->name('backpack-appointment.cancel');
    Route::post('appointment/{id}/no-show', [AppointmentCrudController::class, 'noShow'])->name('backpack-appointment.no-show');
    Route::post('customer/{id}/reveal', [CustomerCrudController::class, 'reveal'])->name('backpack-customer.reveal');
});
