<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name')->index();
            $table->unsignedBigInteger('country_id');
            $table->char('country_code', 2)->nullable();
            $table->string('country_name')->nullable();
            $table->string('iso2')->nullable();
            $table->string('iso3166_2', 10)->nullable();
            $table->string('fips_code')->nullable();
            $table->string('type', 191)->nullable();
            $table->integer('level')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('native')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('timezone')->nullable();
            $table->unsignedBigInteger('population')->nullable();
            $table->string('wikiDataId')->nullable();
            $table->timestamps();

            $table->foreign('country_id')->references('id')->on('countries');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('states');
    }
};
