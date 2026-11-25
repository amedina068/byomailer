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
        Schema::table('sequence_steps', function (Blueprint $table) {
            $table->string('delay_type')->default('days')->after('delay_days');
        });
    }

    public function down(): void
    {
        Schema::table('sequence_steps', function (Blueprint $table) {
            $table->dropColumn('delay_type');
        });
    }
};
