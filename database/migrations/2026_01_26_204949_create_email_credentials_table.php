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
        Schema::create('email_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('ses');
            $table->text('access_key');
            $table->text('secret_key');
            $table->string('region', 50)->default('us-east-1');
            $table->string('configuration_set_name', 100)->nullable();
            $table->timestamp('configuration_set_created_at')->nullable();
            $table->string('sns_topic_arn')->nullable();
            $table->string('sns_subscription_arn')->nullable();
            $table->timestamp('sns_subscription_confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_credentials');
    }
};
