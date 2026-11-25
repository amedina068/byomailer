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
        Schema::table('email_credentials', function (Blueprint $table) {
            $table->string('tenant_name', 64)->nullable()->after('region');
            $table->string('aws_account_id', 12)->nullable()->after('tenant_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_credentials', function (Blueprint $table) {
            $table->dropColumn(['tenant_name', 'aws_account_id']);
        });
    }
};
