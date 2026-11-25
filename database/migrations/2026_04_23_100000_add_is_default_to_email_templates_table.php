<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('html_content');
            $table->timestamp('archived_at')->nullable()->after('is_default');
            $table->index(['client_id', 'is_default']);
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table): void {
            $table->dropIndex(['archived_at']);
            $table->dropIndex(['client_id', 'is_default']);
            $table->dropColumn('archived_at');
            $table->dropColumn('is_default');
        });
    }
};
