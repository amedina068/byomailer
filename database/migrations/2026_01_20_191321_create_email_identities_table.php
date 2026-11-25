<?php

use App\Models\User;
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
        Schema::create('email_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('email');
            $table->string('original_email')->nullable();
            $table->string('name')->nullable();
            $table->string('domain')->nullable();
            $table->string('mail_from_subdomain')->nullable();
            $table->string('region')->nullable();
            $table->string('identity_status')->default('pending');
            $table->timestamp('last_status_check_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_identities');
    }
};
