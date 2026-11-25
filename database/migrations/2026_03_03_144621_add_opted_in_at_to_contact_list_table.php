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
        Schema::table('contact_list', function (Blueprint $table) {
            $table->timestamp('opted_in_at')->nullable()->after('list_id');
        });
    }

    public function down(): void
    {
        Schema::table('contact_list', function (Blueprint $table) {
            $table->dropColumn('opted_in_at');
        });
    }
};
