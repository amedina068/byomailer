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
            $table->boolean('ab_test_enabled')->default(false)->after('content_variables');
            $table->string('ab_template_name')->nullable()->after('ab_test_enabled');
            $table->string('ab_test_winner_variant', 1)->nullable()->after('ab_template_name');
        });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropColumn(['ab_test_enabled', 'ab_template_name', 'ab_test_winner_variant']);
        });
    }
};
