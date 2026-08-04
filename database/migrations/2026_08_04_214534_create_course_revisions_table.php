<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('course_revisions', function (Blueprint $table) {
            $table->id();
            // Nullable + nullOnDelete so the audit row survives a course/user delete.
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('course_name'); // snapshot (identifies deleted courses)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable(); // snapshot of the editor
            $table->string('action', 20); // created | updated | deleted
            $table->json('changes')->nullable(); // compact list of {label, detail}
            $table->timestamp('created_at')->nullable();

            $table->index(['course_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_revisions');
    }
};
