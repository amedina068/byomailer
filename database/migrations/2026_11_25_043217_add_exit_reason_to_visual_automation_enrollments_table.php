<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a nullable column at the end of the table. MySQL 8 adds it
     * instantly, with no copy of the table.
     */
    public function up(): void
    {
        Schema::table('visual_automation_enrollments', function (Blueprint $table) {
            $table->string('exit_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visual_automation_enrollments', function (Blueprint $table) {
            $table->dropColumn('exit_reason');
        });
    }
};
