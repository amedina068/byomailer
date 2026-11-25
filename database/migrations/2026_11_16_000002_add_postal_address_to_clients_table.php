<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The physical postal address that CAN-SPAM requires in every marketing email.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('postal_address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('postal_address');
        });
    }
};
