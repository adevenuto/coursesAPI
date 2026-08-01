<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // How a course's geo columns were derived:
            // address | latlng | layout_data | borrowed | geocoded_name
            $table->string('geo_source', 20)->nullable()->after('country_id');
            $table->decimal('geo_confidence', 4, 3)->nullable()->after('geo_source');
            $table->boolean('needs_review')->default(false)->after('geo_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['geo_source', 'geo_confidence', 'needs_review']);
        });
    }
};
