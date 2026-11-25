<?php

use App\Models\Message;
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
        Schema::table('contact_mails', function (Blueprint $table): void {
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete()->after('mailable_id');
        });
    }

    public function down(): void
    {
        Schema::table('contact_mails', function (Blueprint $table): void {
            $table->dropForeignIdFor(Message::class);
            $table->dropColumn('message_id');
        });
    }
};
