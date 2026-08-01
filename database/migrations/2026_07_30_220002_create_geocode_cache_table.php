<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geocode_cache', function (Blueprint $table) {
            $table->id();
            $table->string('query')->unique();
            $table->string('provider', 40)->default('nominatim');
            $table->longText('response_json')->nullable();
            $table->decimal('resolved_lat', 10, 8)->nullable();
            $table->decimal('resolved_lng', 11, 8)->nullable();
            // accepted | ambiguous | not_found
            $table->string('status', 20)->index();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geocode_cache');
    }
};
