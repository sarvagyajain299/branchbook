<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 60)->nullable();  // NULL = system (scheduler)
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 60);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();           // PII must be masked before it gets here
            $table->string('ip_hash', 64)->nullable();     // hashed so we never store raw IPs
            $table->dateTime('created_at');                // no updated_at: rows are never edited

            $table->index(['subject_type', 'subject_id']); // "history of appointment 42"
            $table->index(['actor_type', 'actor_id']);     // "everything user 7 did"
            $table->index('created_at');                   // date-range filter
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
