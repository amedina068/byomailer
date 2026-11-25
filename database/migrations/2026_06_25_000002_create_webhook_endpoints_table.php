<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Client::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('url');
            $table->text('secret');
            $table->string('description')->nullable();
            $table->json('events');
            $table->boolean('active')->default(true);
            $table->string('disabled_reason')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
