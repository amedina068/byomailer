<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('filters');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'name']);
        });

        Schema::table('newsletters', function (Blueprint $table): void {
            $table->foreignId('segment_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
        });

        Schema::table('sequences', function (Blueprint $table): void {
            $table->foreignId('segment_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('segment_id');
        });

        Schema::table('sequences', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('segment_id');
        });

        Schema::dropIfExists('segments');
    }
};
