<?php

use App\Models\EmailTemplate;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\SequenceStep;
use App\Scopes\ClientScope;
use App\Support\ContentSlot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Soundasleep\Html2Text;

return new class extends Migration
{
    public function up(): void
    {
        $defaultsByClient = EmailTemplate::query()
            ->withoutGlobalScope(ClientScope::class)
            ->where('is_default', true)
            ->get()
            ->keyBy('client_id');

        if ($defaultsByClient->isEmpty()) {
            return;
        }

        Message::query()
            ->withoutGlobalScope(ClientScope::class)
            ->whereNull('email_template_id')
            ->whereIn('messageable_type', [Newsletter::class, SequenceStep::class])
            ->chunkById(200, function ($messages) use ($defaultsByClient): void {
                foreach ($messages as $message) {
                    $clientId = DB::table('users')
                        ->where('id', $message->user_id)
                        ->value('client_id');

                    if ($clientId === null) {
                        continue;
                    }

                    $defaultTemplate = $defaultsByClient->get($clientId);

                    if ($defaultTemplate === null) {
                        continue;
                    }

                    $originalHtml = (string) ($message->html_content ?? '');

                    $composed = ContentSlot::hydrate($defaultTemplate->html_content, $originalHtml);

                    if ($composed === '') {
                        continue;
                    }

                    $flattened = ContentSlot::unwrap($composed);

                    $text = Html2Text::convert($flattened, [
                        'ignore_errors' => true,
                        'drop_links' => false,
                    ]);

                    $message->forceFill([
                        'email_template_id' => $defaultTemplate->id,
                        'template_slot_content' => $originalHtml,
                        'html_content' => $composed,
                        'text_content' => $text,
                    ])->save();
                }
            });
    }

    public function down(): void {}
};
