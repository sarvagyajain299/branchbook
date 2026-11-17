<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\QueueController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\TokenController;
use Illuminate\Support\Facades\Route;

// Versioned: /api/v1/... Every limiter has its own name (3rd argument) so counters are separate.
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    // ---- Public, rate limited ---------------------------------------------------------------
    Route::middleware('throttle:60,1,api-public')->group(function (): void {
        Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
        Route::get('services', [ServiceController::class, 'index'])->name('services.index');
        Route::get('branches/{branch:code}/availability', [AvailabilityController::class, 'show'])->name('availability');
    });

    // ---- Bookings: the verify step and the manage token are the credentials -------------------
    Route::post('bookings', [BookingController::class, 'store'])->middleware('throttle:10,1,api-book')->name('bookings.store');
    Route::post('bookings/{reference}/verify', [BookingController::class, 'verify'])->middleware('throttle:10,1,api-verify')->name('bookings.verify');
    Route::middleware('throttle:30,1,api-manage')->group(function (): void {
        Route::get('bookings/{reference}', [BookingController::class, 'show'])->name('bookings.show');
        Route::delete('bookings/{reference}', [BookingController::class, 'destroy'])->name('bookings.destroy');
    });

    // ---- Staff: tokens with per-role abilities -------------------------------------------------
    Route::post('auth/tokens', [TokenController::class, 'store'])->middleware('throttle:5,1,api-login')->name('tokens.store');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::delete('auth/tokens/current', [TokenController::class, 'destroy'])->name('tokens.destroy');

        Route::get('branches/{branch:code}/queue', [QueueController::class, 'index'])->middleware('abilities:queue:read')->name('queue.index');
        Route::post('branches/{branch:code}/queue/walk-ins', [QueueController::class, 'walkIn'])->middleware('abilities:queue:issue')->name('queue.walk-in');
        Route::post('queue/call-next', [QueueController::class, 'callNext'])->middleware('abilities:queue:operate')->name('queue.call-next');
        Route::patch('queue-tickets/{ticket}', [QueueController::class, 'update'])->middleware('abilities:queue:operate')->name('queue.update');
    });
});
