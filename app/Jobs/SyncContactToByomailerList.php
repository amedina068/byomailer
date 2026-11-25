<?php

namespace App\Jobs;

use App\Services\ByomailerClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncContactToByomailerList implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>  $extraAttributes
     */
    public function __construct(
        public string $email,
        public ?string $name,
        public array $tags,
        public array $extraAttributes = [],
    ) {}

    public function handle(ByomailerClient $client): void
    {
        if (! $client->isConfigured()) {
            return;
        }

        $payload = array_merge([
            'email' => $this->email,
            'name' => $this->name,
            'tags' => $this->tags,
        ], $this->extraAttributes);

        try {
            $response = $client->addContact($payload);

            if ($response->failed()) {
                Log::warning('byomailer list sync failed', [
                    'email' => $this->email,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $this->release(120);
            }
        } catch (Throwable $e) {
            Log::warning('byomailer list sync exception', [
                'email' => $this->email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
