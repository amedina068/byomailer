<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('timezone')->default('UTC')->after('email');
        });

        DB::statement('
            UPDATE clients SET timezone = (
                SELECT users.timezone FROM users
                WHERE users.client_id = clients.id
                ORDER BY users.id ASC
                LIMIT 1
            )
            WHERE EXISTS (
                SELECT 1 FROM users WHERE users.client_id = clients.id
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
