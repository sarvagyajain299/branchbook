<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NEVER add Emirates ID, account or card number columns here.
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('email')->index();              // lookup key (phone is encrypted, so not searchable)
            $table->text('phone');                         // `encrypted` cast stores long ciphertext, so TEXT
            $table->dateTime('email_verified_at')->nullable();
            $table->boolean('marketing_opt_in')->default(false);
            $table->dateTime('last_activity_at')->nullable()->index(); // retention job scans this
            $table->timestamps();
        });

        Schema::create('otp_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('purpose', 30);                 // e.g. booking_verification
            $table->string('code_hash');                   // only the hash is stored, never the 6 digits
            $table->dateTime('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['email', 'purpose']);           // "latest code for this email + purpose"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
        Schema::dropIfExists('customers');
    }
};
