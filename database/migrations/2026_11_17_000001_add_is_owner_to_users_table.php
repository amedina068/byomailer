<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The owner runs the instance. Only the owner can open the admin area.
 *
 * The `admin` role of a user stays a team role inside one client. Before this
 * column, ADMIN_EMAILS was the only list of the operators of the instance. The
 * users in that list become owners here, so they keep their access. A user
 * must have a verified email. Without that check, a person who registers
 * with a listed email that nobody uses yet becomes an owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_owner')->default(false)->after('role');
        });

        $operatorEmails = array_map('strtolower', config('app.admin_emails', []));

        if ($operatorEmails !== []) {
            DB::table('users')
                ->whereIn(DB::raw('lower(email)'), $operatorEmails)
                ->whereNotNull('email_verified_at')
                ->update(['is_owner' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_owner');
        });
    }
};
