<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 20)->unique();     // NWB-7KQ4-P2XD, what the customer quotes
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('advisor_id')->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');                 // UTC. DATETIME (not TIMESTAMP): no session-timezone surprises
            $table->dateTime('ends_at');
            $table->string('status', 20);
            $table->string('channel', 10);
            $table->dateTime('hold_expires_at')->nullable();
            $table->string('manage_token_hash', 64)->nullable(); // sha256 of the emailed token
            $table->string('cancelled_reason')->nullable();
            $table->foreignId('rescheduled_from_id')->nullable()
                ->constrained('appointments')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'starts_at']);     // a branch's day list
            $table->index(['advisor_id', 'starts_at']);    // overlap check + load balancing
            $table->index(['status', 'hold_expires_at']);  // scheduler: expire stale holds
        });

        // Double-booking guard, enforced by MySQL itself.
        //
        // active_slot_key is "advisorId|startTime" while the appointment is active and NULL
        // otherwise. MySQL treats NULLs as distinct in a unique index, so any number of
        // cancelled/expired rows can share a slot, but two ACTIVE rows for the same advisor and
        // start time are rejected even if application code has a bug.
        //
        // The status list is hard-coded on purpose (a migration must not change meaning when an
        // enum changes). A test asserts it matches AppointmentStatus::active().
        DB::statement(<<<'SQL'
            ALTER TABLE appointments
                ADD COLUMN active_slot_key VARCHAR(64)
                    GENERATED ALWAYS AS (
                        IF(status IN ('held','confirmed','checked_in','in_service'),
                           CONCAT(advisor_id, '|', starts_at),
                           NULL)
                    ) STORED,
                ADD UNIQUE INDEX appointments_active_slot_unique (active_slot_key)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
