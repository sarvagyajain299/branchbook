<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table): void {
            // The wait-time and peak-hour reports filter on a date RANGE of issued_at, optionally
            // narrowed to one branch. issued_at first (the range), branch_id second (the filter).
            $table->index(['issued_at', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table): void {
            $table->dropIndex(['issued_at', 'branch_id']);
        });
    }
};
