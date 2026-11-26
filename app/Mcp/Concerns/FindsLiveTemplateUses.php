<?php

namespace App\Mcp\Concerns;

use App\Enums\NewsletterStatus;
use App\Enums\SequenceStatus;
use App\Models\EmailTemplate;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\SequenceStep;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

trait FindsLiveTemplateUses
{
    /**
     * List the live records that send with the template: newsletters that are
     * scheduled or sending, and the steps of live sequences. A message that is
     * not sent yet uses the current template HTML at send time, so a template
     * change reaches real contacts through these records.
     *
     * @return list<array{type: string, id: int, name: ?string, status: string, sequence_id: ?int}>
     */
    protected function liveTemplateUses(EmailTemplate $template): array
    {
        $uses = Message::query()
            ->where('email_template_id', $template->id)
            ->whereHasMorph('messageable', [Newsletter::class, SequenceStep::class], function (Builder $query, string $type): void {
                if ($type === Newsletter::class) {
                    $query->whereIn('status', NewsletterStatus::scheduledOrSending());

                    return;
                }

                $query->whereHas('sequence', fn (Builder $sequence): Builder => $sequence->where('status', SequenceStatus::Live));
            })
            ->with(['messageable' => fn (MorphTo $morphTo): MorphTo => $morphTo->morphWith([SequenceStep::class => ['sequence']])])
            ->orderBy('id')
            ->get()
            ->map(fn (Message $message): ?array => $this->liveUse($message->messageable))
            ->filter()
            ->unique(fn (array $use): string => $use['type'].':'.$use['id'])
            ->values()
            ->all();

        /** @var list<array{type: string, id: int, name: ?string, status: string, sequence_id: ?int}> $uses */
        return $uses;
    }

    /**
     * @return array{type: string, id: int, name: ?string, status: string, sequence_id: ?int}|null
     */
    private function liveUse(mixed $messageable): ?array
    {
        if ($messageable instanceof Newsletter) {
            return [
                'type' => 'newsletter',
                'id' => $messageable->id,
                'name' => $messageable->title,
                'status' => $messageable->status->value,
                'sequence_id' => null,
            ];
        }

        if ($messageable instanceof SequenceStep) {
            return [
                'type' => 'sequence_step',
                'id' => $messageable->id,
                'name' => $messageable->sequence?->name,
                'status' => $messageable->status->value,
                'sequence_id' => $messageable->sequence_id,
            ];
        }

        return null;
    }
}
