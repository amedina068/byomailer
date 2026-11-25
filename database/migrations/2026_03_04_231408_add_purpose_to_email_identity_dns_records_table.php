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
        Schema::table('email_identity_dns_records', function (Blueprint $table) {
            $table->string('purpose', 20)->nullable()->after('priority');
        });

        DB::table('email_identity_dns_records')
            ->where('type', 'CNAME')
            ->where('name', 'like', '%_domainkey%')
            ->update(['purpose' => 'dkim']);

        DB::table('email_identity_dns_records')
            ->whereIn('type', ['MX', 'TXT'])
            ->where('name', 'like', 'mas.%')
            ->update(['purpose' => 'mail_from']);

        DB::table('email_identity_dns_records')
            ->where('type', 'TXT')
            ->where('name', 'like', '_dmarc.%')
            ->update(['purpose' => 'dmarc']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_identity_dns_records', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
