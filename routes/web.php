<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Booking\BookingController;
use App\Http\Controllers\Booking\ManageBookingController;
use App\Http\Controllers\Booking\VerificationController;
use App\Http\Controllers\Branch\AdvisorDeskController;
use App\Http\Controllers\Branch\DisplayController;
use App\Http\Controllers\Branch\FrontDeskController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// ---- Public customer booking journey (no login; verified by email code) ---------------------
Route::get('/', [BookingController::class, 'services'])->name('book.services');
Route::redirect('/book', '/');

// Each throttle has its own name (the 3rd argument). Without one, every throttle:N,M route
// shares a single per-IP counter, so ordinary page views would use up the resend budget.
// Verification and management routes first: their paths must not be read as {service}/{branch}.
Route::get('/booking/verify/{reference}', [VerificationController::class, 'show'])->name('booking.verify');
Route::post('/booking/verify/{reference}', [VerificationController::class, 'verify'])
    ->middleware('throttle:10,1,verify')->name('booking.verify.submit');
Route::post('/booking/verify/{reference}/resend', [VerificationController::class, 'resend'])
    ->middleware('throttle:3,10,resend')->name('booking.verify.resend');

Route::get('/booking/{reference}', [ManageBookingController::class, 'show'])->middleware('throttle:30,1,manage')->name('booking.show');
Route::get('/booking/{reference}/calendar.ics', [ManageBookingController::class, 'calendar'])->middleware('throttle:30,1,calendar')->name('booking.calendar');
Route::post('/booking/{reference}/cancel', [ManageBookingController::class, 'cancel'])->middleware('throttle:10,1,cancel')->name('booking.cancel');

// {service:code}/{branch:code} are two independent models. Without this, Laravel assumes the
// branch is a CHILD of the service and looks for Service::branches().
Route::withoutScopedBindings()->group(function (): void {
    Route::get('/book/{service:code}', [BookingController::class, 'branches'])->name('book.branches');
    Route::get('/book/{service:code}/{branch:code}', [BookingController::class, 'times'])->name('book.times');
    Route::get('/book/{service:code}/{branch:code}/slots', [BookingController::class, 'slots'])->middleware('throttle:60,1,slots')->name('book.slots');
    Route::get('/book/{service:code}/{branch:code}/details', [BookingController::class, 'details'])->name('book.details');
    Route::post('/book/{service:code}/{branch:code}', [BookingController::class, 'store'])->middleware('throttle:10,1,store')->name('book.store');
});

// ---- Staff area ---------------------------------------------------------------------------
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('reports', [ReportController::class, 'index'])->name('reports');
    Route::get('reports/export/{report}', [ReportController::class, 'csv'])
        ->where('report', 'utilisation|no-shows|wait-times|heatmap|appointments')->name('reports.csv');
});

Route::middleware(['auth', 'verified'])->prefix('branch/{branch:code}')->group(function (): void {
    Route::get('desk', [FrontDeskController::class, 'index'])->name('desk');
    Route::post('desk/check-in/{appointment}', [FrontDeskController::class, 'checkIn'])->name('desk.check-in');
    Route::post('desk/walk-in', [FrontDeskController::class, 'walkIn'])->name('desk.walk-in');

    Route::get('my-desk', [AdvisorDeskController::class, 'index'])->name('my-desk');
    Route::post('my-desk/call-next', [AdvisorDeskController::class, 'callNext'])->name('my-desk.call-next');
    Route::post('my-desk/tickets/{ticket}/start', [AdvisorDeskController::class, 'start'])->name('my-desk.start');
    Route::post('my-desk/tickets/{ticket}/complete', [AdvisorDeskController::class, 'complete'])->name('my-desk.complete');
    Route::post('my-desk/tickets/{ticket}/no-show', [AdvisorDeskController::class, 'noShow'])->name('my-desk.no-show');
    Route::post('my-desk/tickets/{ticket}/recall', [AdvisorDeskController::class, 'recall'])->name('my-desk.recall');
});

// Waiting-room TV: public, no personal data, polled every few seconds.
Route::get('/display/{branch:code}', [DisplayController::class, 'show'])->name('display');
Route::get('/display/{branch:code}/state', [DisplayController::class, 'state'])
    ->middleware('throttle:120,1,display')->name('display.state');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
