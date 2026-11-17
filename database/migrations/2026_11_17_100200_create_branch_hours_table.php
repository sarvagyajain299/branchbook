<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');        // ISO-8601: 1 = Monday ... 7 = Sunday
            $table->time('opens_at');                      // branch-local (Dubai) wall-clock time
            $table->time('closes_at');
            $table->time('break_start')->nullable();
            $table->time('break_end')->nullable();
            $table->timestamps();

            // One row per branch per weekday; also serves "hours for branch X on weekday Y".
            // A closed day simply has no row.
            $table->unique(['branch_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_hours');
    }
};
