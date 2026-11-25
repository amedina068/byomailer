<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\Newsletter;
use App\Models\SequenceStep;
use App\Scopes\ClientScope;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResyncMessageTemplates extends Command
{
    protected $signature = 'messages:resync-templates
        {--template= : Only resync messages bound to this template id}';

    protected $description = 'Recompose stored html/text for not-yet-sent newsletter and sequence step messages from their current template.';

    public function handle(): int
    {
        $templateId = $this->option('template');
        if ($templateId !== null && (! ctype_digit((string) $templateId) || (int) $templateId < 1)) {
            $this->error('The template id must be a positive integer.');

            return self::INVALID;
        }

        $updated = 0;

        Message::query()
            ->withoutGlobalScope(ClientScope::class)
            ->whereNotNull('email_template_id')
            ->when($templateId !== null, fn (Builder $q): Builder => $q->where('email_template_id', $templateId))
            ->where(function (Builder $query): void {
                $query->whereHasMorph(
                    'messageable',
                    Newsletter::class,
                    fn (Builder $n): Builder => $n->withoutGlobalScope(ClientScope::class)
                        ->mutable(),
                )->orWhere('messageable_type', SequenceStep::class);
            })
            ->with(['template' => fn (BelongsTo $query): BelongsTo => $query->withoutGlobalScope(ClientScope::class)])
            ->chunkById(200, function (Collection $messages) use (&$updated): void {
                foreach ($messages as $message) {
                    $updated += Tenant::run($message->client_id, fn (): int => $this->resync($message));
                }
            });

        $this->info("Resynced {$updated} message(s) from their templates.");

        return self::SUCCESS;
    }

    /**
     * Compose the message from its template again. Return 1 when the content changed, else 0.
     */
    private function resync(Message $message): int
    {
        if (! $message->currentTemplate()) {
            return 0;
        }

        $html = $message->composedHtml();
        $text = $message->composedText();

        if ($html === (string) $message->html_content && $text === (string) $message->text_content) {
            return 0;
        }

        $message->forceFill([
            'html_content' => $html,
            'text_content' => $text,
        ])->save();

        return 1;
    }
}
