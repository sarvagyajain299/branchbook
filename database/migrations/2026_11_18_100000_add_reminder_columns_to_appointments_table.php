<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            // Set once when the reminder is claimed; makes reminders idempotent.
            $table->dateTime('reminder_sent_at')->nullable()->after('hold_expires_at');
            // The scheduler asks "confirmed appointments starting between X and Y".
            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropIndex(['status', 'starts_at']);
            $table->dropColumn('reminder_sent_at');
        });
    }
};
