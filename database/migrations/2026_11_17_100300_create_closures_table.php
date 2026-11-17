<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete(); // NULL = every branch
            $table->date('date');
            $table->string('reason');
            $table->timestamps();

            // Availability asks: "is there a closure on :date for :branch or for all (NULL)?"
            $table->index(['date', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('closures');
    }
};
