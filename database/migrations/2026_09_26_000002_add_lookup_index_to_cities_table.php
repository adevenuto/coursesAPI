<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite index for the public city typeahead (`/geo/cities`), which always
 * filters `state_id` and then matches `name` with a leading-wildcard LIKE.
 *
 * This is NOT redundant with `cities_name_index` — please don't drop it on that
 * reasoning. Measured with EXPLAIN ANALYZE on the worst-case state (3009, 1,757
 * cities), `WHERE state_id = ? AND name LIKE '%san%' ORDER BY name LIMIT 10`:
 *
 *   without this index  full scan of cities_name_index  152,970 rows   206 ms
 *   FORCE INDEX(state_id FK)  index lookup + filter       1,757 rows     5 ms
 *
 * The FK index alone already produces the good plan; the optimizer just won't
 * pick it, because cities_name_index looks cheaper (it avoids the filesort) and
 * then isn't. Laravel has no forceIndex() builder method, so the fix is to make
 * the good plan the automatic one: a range scan on state_id, index-ordered by
 * name so the sort disappears too.
 *
 * 206 ms per keystroke on an unauthenticated endpoint is a self-inflicted DoS,
 * which is what makes this an index rather than a nice-to-have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->index(['state_id', 'name'], 'cities_state_id_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropIndex('cities_state_id_name_index');
        });
    }
};
