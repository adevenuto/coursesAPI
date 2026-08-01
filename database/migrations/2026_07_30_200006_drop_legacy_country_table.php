<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('country');
    }

    /**
     * Recreate the legacy ISO country table for reversibility.
     */
    public function down(): void
    {
        Schema::create('country', function (Blueprint $table) {
            $table->increments('id');
            $table->char('iso', 2)->nullable();
            $table->string('name', 80)->nullable();
            $table->string('nicename', 80)->nullable();
            $table->char('iso3', 3)->nullable();
            $table->smallInteger('numcode')->nullable();
            $table->integer('phonecode')->nullable();
        });
    }
};
