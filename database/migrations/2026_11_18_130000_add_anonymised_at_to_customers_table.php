<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dateTime('anonymised_at')->nullable()->after('last_activity_at');
            $table->index('anonymised_at');   // the retention job asks for rows where this IS NULL
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['anonymised_at']);
            $table->dropColumn('anonymised_at');
        });
    }
};
