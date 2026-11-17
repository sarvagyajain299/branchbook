<?php

declare(strict_types=1);

use App\Http\Controllers\Booking\BookingController;
use App\Http\Controllers\Booking\ManageBookingController;
use App\Http\Controllers\Booking\VerificationController;
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

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
