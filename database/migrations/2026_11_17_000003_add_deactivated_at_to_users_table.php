<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner deactivates a user instead of deleting the user. A deactivated user
 * keeps all data and cannot authenticate. A null value means active.
 *
 * This column is not the same as `archived_at`. A team admin archives a member
 * of one client. The owner deactivates a user of the whole instance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
