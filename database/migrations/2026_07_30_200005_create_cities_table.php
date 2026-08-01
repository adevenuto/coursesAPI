<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name')->index();
            $table->unsignedBigInteger('state_id');
            $table->string('state_code')->nullable();
            $table->string('state_name')->nullable();
            $table->unsignedBigInteger('country_id');
            $table->char('country_code', 2)->nullable();
            $table->string('country_name')->nullable();
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->string('native')->nullable();
            $table->string('type', 191)->nullable();
            $table->integer('level')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->unsignedBigInteger('population')->nullable();
            $table->string('timezone')->nullable();
            $table->string('wikiDataId')->nullable();
            $table->timestamps();

            $table->foreign('state_id')->references('id')->on('states');
            $table->foreign('country_id')->references('id')->on('countries');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
