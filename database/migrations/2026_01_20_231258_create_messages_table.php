<?php

use App\Models\EmailIdentity;
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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->morphs('messageable');
            $table->foreignIdFor(EmailIdentity::class)->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->longText('html_content')->nullable();
            $table->longText('text_content')->nullable();
            $table->longText('markdown_content')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
