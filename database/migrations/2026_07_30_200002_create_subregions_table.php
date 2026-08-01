<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subregions', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name', 100)->index();
            $table->unsignedBigInteger('region_id');
            $table->string('wikiDataId')->nullable();
            $table->timestamps();

            $table->foreign('region_id')->references('id')->on('regions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subregions');
    }
};
