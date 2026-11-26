<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An account can connect its AWS account through an IAM role in place of
     * access keys. The role credential has no keys, so the key columns become
     * nullable. Each client gets one external ID for the trust policy.
     */
    public function up(): void
    {
        Schema::table('email_credentials', function (Blueprint $table) {
            $table->text('access_key')->nullable()->change();
            $table->text('secret_key')->nullable()->change();
            $table->string('role_arn')->nullable()->after('secret_key');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('aws_external_id', 64)->nullable()->unique();
        });
    }

    /**
     * The key columns stay nullable. A role credential has no keys, so the old
     * NOT NULL rule fails on those rows.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['aws_external_id']);
            $table->dropColumn('aws_external_id');
        });

        Schema::table('email_credentials', function (Blueprint $table) {
            $table->dropColumn('role_arn');
        });
    }
};
