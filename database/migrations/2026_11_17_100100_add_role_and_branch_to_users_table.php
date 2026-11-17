<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Least privilege by default: a row created without an explicit role gets the
            // most restricted one, never HQ admin.
            $table->string('role', 20)->default('front_desk')->after('password');
            // NULL = HQ staff (not tied to a branch). restrict: a branch with staff can't be deleted.
            $table->foreignId('branch_id')->nullable()->after('role')
                ->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn('role');
        });
    }
};
