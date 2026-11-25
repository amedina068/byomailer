<?php

use App\Models\SequenceStep;
use App\Services\SequenceStepScheduler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_sequence', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable();
            $table->index('completed_at');
        });

        $this->backfillCompletedContacts();
    }

    public function down(): void
    {
        Schema::table('contact_sequence', function (Blueprint $table) {
            $table->dropIndex(['completed_at']);
            $table->dropColumn('completed_at');
        });
    }

    /**
     * Mark contacts who already received their sequence's final step as completed,
     * stamped with that step's delivery time. Mirrors the runtime rule: a contact
     * finishes when the last step's mail is delivered and no later step exists.
     */
    private function backfillCompletedContacts(): void
    {
        DB::table('sequences')->pluck('id')->each(function (int $sequenceId): void {
            $lastStepId = DB::table('sequence_steps')
                ->where('sequence_id', $sequenceId)
                ->orderByRaw(SequenceStepScheduler::DELAY_IN_HOURS_SQL.' DESC')
                ->orderBy('id', 'desc')
                ->value('id');

            if (! $lastStepId) {
                return;
            }

            DB::table('contact_mails')
                ->where('mailable_type', SequenceStep::class)
                ->where('mailable_id', $lastStepId)
                ->whereNotNull('delivered_at')
                ->select('contact_id', DB::raw('MAX(delivered_at) as completed_at'))
                ->groupBy('contact_id')
                ->get()
                ->each(function ($row) use ($sequenceId): void {
                    DB::table('contact_sequence')
                        ->where('sequence_id', $sequenceId)
                        ->where('contact_id', $row->contact_id)
                        ->whereNull('completed_at')
                        ->update(['completed_at' => $row->completed_at]);
                });
        });
    }
};
