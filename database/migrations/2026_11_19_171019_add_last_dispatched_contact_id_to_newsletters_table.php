<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The send job stores the last contact that got a chunk job here.
     * A retry of the job continues after this contact.
     */
    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->unsignedBigInteger('last_dispatched_contact_id')->nullable()->after('total_contacts_reached');
        });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropColumn('last_dispatched_contact_id');
        });
    }
};
