<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `contact_mail_clicks` is a large table. The columns are nullable and
     * have no `after()`, so MySQL 8 adds them with an instant ALTER and does
     * not copy the table. Clicks from before this migration keep NULL.
     *
     * The link report reads clicks through `contact_mail_id`, which has an
     * index from its foreign key. So the new columns need no index.
     */
    public function up(): void
    {
        Schema::table('contact_mail_clicks', function (Blueprint $table) {
            $table->string('link_name', 100)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('ip_address', 45)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_mail_clicks', function (Blueprint $table) {
            $table->dropColumn(['link_name', 'user_agent', 'ip_address']);
        });
    }
};
