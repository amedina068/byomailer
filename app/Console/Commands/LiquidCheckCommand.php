<?php

namespace App\Console\Commands;

use App\Enums\NewsletterStatus;
use App\Models\MailList;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\SequenceStep;
use App\Scopes\ClientScope;
use App\Support\Liquid;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class LiquidCheckCommand extends Command
{
    protected $signature = 'messages:liquid-check';

    protected $description = 'List the unsent messages whose content is not plain {{ variable }} placeholders (read-only)';

    public function handle(): int
    {
        $rows = [];

        Message::query()
            ->withoutGlobalScope(ClientScope::class)
            ->where(function (Builder $query): void {
                $query->whereHasMorph(
                    'messageable',
                    Newsletter::class,
                    fn (Builder $newsletter): Builder => $newsletter->withoutGlobalScope(ClientScope::class)
                        ->whereNotIn('status', [NewsletterStatus::Sent, NewsletterStatus::Failed]),
                )->orWhereIn('messageable_type', [SequenceStep::class, MailList::class]);
            })
            ->lazyById(200)
            ->each(function (Message $message) use (&$rows): void {
                if ($row = Tenant::run($message->client_id, fn (): ?array => $this->check($message))) {
                    $rows[] = $row;
                }
            });

        if ($rows === []) {
            $this->info('Each unsent message uses plain {{ variable }} placeholders only.');

            return self::SUCCESS;
        }

        $this->table(['Message', 'Client', 'Result'], $rows);

        $liquid = collect($rows)->filter(fn (array $row): bool => $row[2] === 'Renders with Liquid')->count();
        $broken = count($rows) - $liquid;

        $this->line("{$liquid} message(s) render with Liquid. {$broken} message(s) are not valid Liquid: they send as before, and the editor asks for a correction on the next save.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: int, 2: string}|null
     */
    private function check(Message $message): ?array
    {
        $parts = array_map(strval(...), [$message->subject, $message->preview_text, $message->html_content, $message->text_content]);

        if (Liquid::isPlain(implode("\n", $parts))) {
            return null;
        }

        $label = class_basename($message->messageable_type).' '.$message->messageable_id;

        if ($message->usesLiquid()) {
            return [$label, $message->client_id, 'Renders with Liquid'];
        }

        $error = collect($parts)->map(Liquid::error(...))->filter()->first();

        return [$label, $message->client_id, 'Sends as before. '.Str::limit((string) $error, 120)];
    }
}
