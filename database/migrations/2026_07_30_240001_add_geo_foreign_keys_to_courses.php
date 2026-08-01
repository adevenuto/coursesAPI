<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // FKs auto-create indexes on city_id / state_prov_id (country_id is
            // already indexed) and enforce referential integrity for writes.
            $table->foreign('city_id')->references('id')->on('cities')->nullOnDelete();
            $table->foreign('state_prov_id')->references('id')->on('states')->nullOnDelete();
            $table->foreign('country_id')->references('id')->on('countries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign(['city_id']);
            $table->dropForeign(['state_prov_id']);
            $table->dropForeign(['country_id']);
        });
    }
};
