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
        Schema::create('email_identity_dns_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_identity_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('name');
            $table->text('value');
            $table->unsignedSmallInteger('priority')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_identity_dns_records');
    }
};
