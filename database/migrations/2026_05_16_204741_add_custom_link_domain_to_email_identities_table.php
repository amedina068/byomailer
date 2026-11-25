<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_identities', function (Blueprint $table): void {
            $table->string('custom_link_domain')->nullable()->after('custom_tracking_domain_verified_at');
            $table->dateTime('custom_link_domain_verified_at')->nullable()->after('custom_link_domain');
            $table->string('cf_custom_hostname_id')->nullable()->after('custom_link_domain_verified_at');

            $table->index('custom_link_domain');
        });
    }

    public function down(): void
    {
        Schema::table('email_identities', function (Blueprint $table): void {
            $table->dropIndex(['custom_link_domain']);
            $table->dropColumn([
                'custom_link_domain',
                'custom_link_domain_verified_at',
                'cf_custom_hostname_id',
            ]);
        });
    }
};
