<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');
            $table->timestamp('invite_expires_at')->nullable()->after('role');
        });

        DB::table('users')
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $user): void {
                if ($user->uuid) {
                    return;
                }

                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['uuid' => (string) Str::uuid()]);
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('uuid');
        });

        DB::table('users')->update(['role' => 'admin']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_uuid_unique');
            $table->dropColumn(['uuid', 'invite_expires_at']);
        });
    }
};
