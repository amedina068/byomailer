<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->string('title')->default('')->after('user_id');
        });

        DB::statement("
            UPDATE newsletters
            SET title = (
                SELECT subject
                FROM messages
                WHERE messages.messageable_id = newsletters.id
                AND messages.messageable_type = 'App\\Models\\Newsletter'
                AND messages.subject != ''
            )
            WHERE EXISTS (
                SELECT 1
                FROM messages
                WHERE messages.messageable_id = newsletters.id
                AND messages.messageable_type = 'App\\Models\\Newsletter'
                AND messages.subject != ''
            )
        ");
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropColumn('title');
        });
    }
};
