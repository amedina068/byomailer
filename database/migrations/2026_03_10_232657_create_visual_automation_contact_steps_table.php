<?php

use App\Models\VisualAutomationEnrollment;
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
        Schema::create('visual_automation_contact_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(VisualAutomationEnrollment::class, 'enrollment_id')->constrained('visual_automation_enrollments')->cascadeOnDelete();
            $table->string('node_id');
            $table->string('status')->default('pending');
            $table->timestamp('wait_until')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['enrollment_id', 'status']);
            $table->unique(['enrollment_id', 'node_id'], 'va_contact_steps_enrollment_node_unique');
            $table->index('node_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visual_automation_contact_steps');
    }
};
