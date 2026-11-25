<?php

namespace App\Support;

use App\Models\Block;
use App\Models\Client;

trait ReplacesBlockVariables
{
    /**
     * Replace {{ block.name }} placeholders with the client's block HTML content.
     *
     * The client is required and the query drops global scopes on purpose: this
     * runs from queued jobs, console commands and webhooks where `ClientScope`
     * is a no-op, so relying on it would match another tenant's block with the
     * same name.
     */
    public function replaceBlockVariables(string $content, Client $client): string
    {
        return $this->substituteBlocks($content, $client, asText: false);
    }

    /**
     * Replace {{ block.name }} placeholders in a `text/plain` body.
     *
     * A block stores HTML. Substituting it into the plain part as-is puts
     * `<p>` tags in front of the reader and is a spam signal, so the block is
     * converted to text first.
     */
    public function replaceBlockVariablesAsText(string $content, Client $client): string
    {
        return $this->substituteBlocks($content, $client, asText: true);
    }

    private function substituteBlocks(string $content, Client $client, bool $asText): string
    {
        if ($content === '') {
            return $content;
        }

        preg_match_all('/\{\{\s*block\.([A-Za-z0-9_]+)\s*\}\}/', $content, $matches);

        if (empty($matches[1])) {
            return $content;
        }

        $blockNames = array_unique($matches[1]);

        $blocks = Block::withoutGlobalScopes()
            ->where('client_id', $client->id)
            ->whereIn('name', $blockNames)
            ->pluck('html_content', 'name');

        foreach ($blockNames as $name) {
            $replacement = (string) $blocks->get($name, '');

            if ($asText) {
                $replacement = trim(HtmlToText::convert($replacement));
            }

            // A callback, not a replacement string: block HTML can contain `$1`
            // or a backslash, which preg_replace would read as a backreference.
            $pattern = '/\{\{\s*block\.'.preg_quote($name, '/').'\s*\}\}/';
            $content = preg_replace_callback($pattern, fn (): string => $replacement, $content);
        }

        return $content;
    }
}
