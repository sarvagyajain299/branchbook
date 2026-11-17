<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('category', 60);
            $table->text('description');
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('buffer_minutes')->default(0);   // wrap-up time after the meeting
            $table->char('queue_prefix', 1);               // A-042: the letter on the ticket
            $table->json('required_documents');            // checklist shown to the customer
            $table->unsignedSmallInteger('min_notice_hours')->default(2);
            $table->unsignedSmallInteger('max_days_ahead')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
