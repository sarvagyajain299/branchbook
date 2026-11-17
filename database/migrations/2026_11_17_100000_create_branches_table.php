<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();          // e.g. DXB-MAR, used in URLs and reports
            $table->string('name');
            $table->string('city', 60);
            $table->string('address');
            $table->decimal('latitude', 10, 7);            // ~1 cm precision, plenty for Haversine
            $table->decimal('longitude', 10, 7);
            $table->string('phone', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['city', 'is_active']);          // "active branches in Dubai" filter
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
