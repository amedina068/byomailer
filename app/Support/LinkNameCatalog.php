<?php

namespace App\Support;

use App\Models\Message;
use Illuminate\Support\Facades\Cache;

/**
 * The link names that the current client uses in its email content. The
 * automation forms and the MCP server offer them for the "link clicked"
 * trigger.
 */
class LinkNameCatalog
{
    private const CACHE_SECONDS = 60;

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return Cache::remember('link-names:'.Tenant::id(), self::CACHE_SECONDS, function (): array {
            $names = [];

            Message::query()
                ->where('html_content', 'like', '%'.NamedLink::ATTRIBUTE.'%')
                ->select(['id', 'html_content'])
                ->lazyById(200)
                ->each(function (Message $message) use (&$names): void {
                    foreach (NamedLink::namesIn($message->html_content) as $name) {
                        $names[$name] = true;
                    }
                });

            $names = array_map('strval', array_keys($names));
            sort($names);

            return $names;
        });
    }
}
