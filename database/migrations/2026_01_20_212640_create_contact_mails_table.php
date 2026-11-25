<?php

use App\Models\Contact;
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
        Schema::create('contact_mails', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignIdFor(Contact::class)->constrained()->cascadeOnDelete();
            $table->nullableMorphs('mailable');
            $table->string('subject')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->json('template_data')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('status')->default('pending'); // ['pending', 'sent', 'delivered', 'opened', 'bounced', 'rejected', 'complained', 'unsubscribed']
            $table->timestamps();

            $table->unique(['contact_id', 'mailable_type', 'mailable_id'], 'contact_mailable_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_mails');
    }
};
