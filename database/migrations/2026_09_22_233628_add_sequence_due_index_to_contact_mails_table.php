<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports the sequence claim seek
     * (WHERE mailable_type = ? AND status = 'pending' AND scheduled_at <= ? ORDER BY scheduled_at LIMIT 200).
     */
    public function up(): void
    {
        Schema::table('contact_mails', function (Blueprint $table): void {
            $table->index(['mailable_type', 'status', 'scheduled_at'], 'contact_mails_sequence_due_index');
        });
    }

    public function down(): void
    {
        Schema::table('contact_mails', function (Blueprint $table): void {
            $table->dropIndex('contact_mails_sequence_due_index');
        });
    }
};
