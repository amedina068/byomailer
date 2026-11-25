<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ByomailerClient
{
    public function __construct(
        private ?string $apiUrl = null,
        private ?string $apiToken = null,
        private ?string $listId = null,
    ) {
        $this->apiUrl ??= config('services.byomailer.api_url');
        $this->apiToken ??= config('services.byomailer.api_token');
        $this->listId ??= config('services.byomailer.list_id');
    }

    public function isConfigured(): bool
    {
        return filled($this->apiUrl) && filled($this->apiToken) && filled($this->listId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addContact(array $data): Response
    {
        return $this->client()
            ->post(rtrim($this->apiUrl, '/').'/v1/lists/'.$this->listId.'/contacts', $data);
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->apiToken)->acceptJson();
    }
}
