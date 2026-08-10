<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cities` got a (latitude, longitude) index; `courses` never did, so every
 * radius query full-scans 22k rows. The leading `lat` range in Course::scopeNear's
 * bounding-box prefilter uses this — it serves both the editor's nearby-courses
 * panel and the explorer's existing near-me search.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->index(['lat', 'lng'], 'courses_lat_lng_index');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex('courses_lat_lng_index');
        });
    }
};
