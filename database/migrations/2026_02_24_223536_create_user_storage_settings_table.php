<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_storage_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('bucket');
            $table->string('region');
            $table->string('endpoint')->nullable();
            $table->text('access_key_id');
            $table->text('secret_access_key');
            $table->string('public_base_url');
            $table->string('path_prefix')->default('byomailer/{user_id}');
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_storage_settings');
    }
};
