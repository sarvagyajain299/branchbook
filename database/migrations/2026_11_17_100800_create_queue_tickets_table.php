<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('service_date');                  // branch-local calendar day; numbering resets daily
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->char('queue_prefix', 1);
            $table->unsignedSmallInteger('sequence');
            $table->string('display_code', 10);            // A-042
            $table->foreignId('appointment_id')->nullable()->unique() // NULL = walk-in; 1 ticket per appointment
                ->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->dateTime('issued_at');
            $table->dateTime('called_at')->nullable();
            $table->dateTime('served_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('advisor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('desk_label', 30)->nullable();
            $table->timestamps();

            // Guarantees no two customers get "A-042" on the same day at the same branch,
            // even if two front desks issue tickets at the same instant.
            $table->unique(['branch_id', 'service_date', 'queue_prefix', 'sequence'], 'queue_tickets_daily_number_unique');
            // "waiting tickets for branch X today": the Call-next and display query.
            $table->index(['branch_id', 'service_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_tickets');
    }
};
