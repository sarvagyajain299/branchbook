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
            $table->string('walk_in_name', 60)->nullable()->after('desk_label'); // optional, front desk typed it
            // Recall re-announces a called ticket. called_at stays untouched so wait-time
            // reports measure the FIRST call.
            $table->dateTime('recalled_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('queue_tickets', function (Blueprint $table): void {
            $table->dropColumn(['walk_in_name', 'recalled_at']);
        });
    }
};
