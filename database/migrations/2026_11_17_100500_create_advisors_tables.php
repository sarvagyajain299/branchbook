<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete(); // 1 user = at most 1 advisor
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('desk_label', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);     // "active advisors at branch X"
        });

        // Which services each advisor is qualified for (many-to-many pivot).
        Schema::create('advisor_service', function (Blueprint $table): void {
            $table->foreignId('advisor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->primary(['advisor_id', 'service_id']); // no duplicates; covers lookups by advisor
            $table->index('service_id');                   // reverse lookup: advisors for a service
        });

        Schema::create('advisor_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advisor_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');        // ISO-8601, 1 = Monday
            $table->time('starts_at');                     // branch-local wall-clock time
            $table->time('ends_at');
            $table->timestamps();

            $table->index(['advisor_id', 'weekday']);
        });

        Schema::create('advisor_time_off', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advisor_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');                 // UTC instants
            $table->dateTime('ends_at');
            $table->string('reason');
            $table->timestamps();

            $table->index(['advisor_id', 'starts_at', 'ends_at']); // overlap check
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_time_off');
        Schema::dropIfExists('advisor_shifts');
        Schema::dropIfExists('advisor_service');
        Schema::dropIfExists('advisors');
    }
};
