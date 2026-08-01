<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name', 100)->index();
            $table->char('iso3', 3)->nullable();
            $table->char('iso2', 2)->nullable()->index();
            $table->string('numeric_code', 3)->nullable();
            $table->string('phonecode')->nullable();
            $table->string('capital')->nullable();
            $table->string('currency')->nullable();
            $table->string('currency_name')->nullable();
            $table->string('currency_symbol')->nullable();
            $table->string('tld')->nullable();
            $table->string('native')->nullable();
            $table->unsignedBigInteger('population')->nullable();
            $table->unsignedBigInteger('gdp')->nullable();
            $table->string('region_name')->nullable();
            $table->unsignedBigInteger('region_id')->nullable();
            $table->string('subregion_name')->nullable();
            $table->unsignedBigInteger('subregion_id')->nullable();
            $table->string('nationality')->nullable();
            $table->double('area_sq_km')->nullable();
            $table->string('postal_code_format')->nullable();
            $table->string('postal_code_regex')->nullable();
            $table->text('timezones')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('emoji', 191)->nullable();
            $table->string('emojiU', 191)->nullable();
            $table->string('wikiDataId')->nullable();
            $table->timestamps();

            $table->foreign('region_id')->references('id')->on('regions')->nullOnDelete();
            $table->foreign('subregion_id')->references('id')->on('subregions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
