<?php

use App\Models\EmailTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignIdFor(EmailTemplate::class)->nullable()->after('email_identity_id')->constrained('email_templates')->nullOnDelete();
            $table->longText('template_slot_content')->nullable()->after('html_content');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(EmailTemplate::class);
            $table->dropColumn('template_slot_content');
        });
    }
};
