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
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('ses')->index();
            $table->string('operation', 50);
            $table->string('recipient', 255)->nullable();
            $table->string('message_id', 100)->nullable()->index();
            $table->string('status', 20)->index();
            $table->text('error')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('sns_payload')->nullable();
            $table->timestamp('logged_at');
            $table->timestamps();

            $table->index(['client_id', 'logged_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
