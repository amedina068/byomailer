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
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->unsignedInteger('total_contacts_reached')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropColumn('total_contacts_reached');
        });
    }
};
