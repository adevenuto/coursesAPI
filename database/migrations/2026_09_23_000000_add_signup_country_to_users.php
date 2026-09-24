<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a user signed up from, as an ISO 3166-1 alpha-2 code.
 *
 * Resolved once from the registering browser's address and stored; the address
 * itself is not kept. Nullable and never backfilled — no signup IP was ever
 * recorded, and reusing a user's earliest API request IP would report where
 * their servers run rather than where they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('signup_country', 2)->nullable()->after('role');
            $table->index('signup_country');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['signup_country']);
            $table->dropColumn('signup_country');
        });
    }
};
