<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Mcp\Admin\RedactsSecrets;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('read-recent-errors')]
#[Title('Read recent errors')]
#[Description('Returns the newest error entries of the Laravel log (ERROR, CRITICAL, ALERT and EMERGENCY, plus WARNING when include_warnings is true). Each entry has the time, the level, the message and the first lines of context. The tool removes keys, tokens and passwords before it returns the text. When the app logs to stderr only, the file list is empty: read the container log instead.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ReadRecentErrorsTool extends AdminTool
{
    use RedactsSecrets;

    /** The tool reads at most this many bytes from the end of the log. */
    private const TAIL_BYTES = 2_000_000;

    private const CONTEXT_LINES = 8;

    public function handle(Request $request): Response
    {
        return $this->audited($request, function () use ($request): Response {
            $input = $request->validate([
                'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
                'include_warnings' => ['nullable', 'boolean'],
            ]);

            $file = $this->newestLog();

            if ($file === null) {
                return self::ok(['file' => null, 'entries' => []]);
            }

            $levels = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY', ...(($input['include_warnings'] ?? false) ? ['WARNING'] : [])];
            $entries = array_values(array_filter(
                $this->entries($this->tail($file)),
                fn (array $entry): bool => in_array($entry['level'], $levels, true),
            ));

            return self::ok([
                'file' => basename($file),
                'entries' => array_map(fn (array $entry): array => [
                    'time' => $entry['time'],
                    'level' => $entry['level'],
                    'message' => $this->redact($entry['message']),
                    'context' => $this->redact($entry['context']),
                ], array_slice(array_reverse($entries), 0, (int) ($input['limit'] ?? 20))),
            ]);
        });
    }

    /**
     * The newest app log. The audit log of this server is not an error log.
     */
    private function newestLog(): ?string
    {
        $files = array_values(array_filter(
            glob(storage_path('logs/*.log')) ?: [],
            fn (string $file): bool => ! str_starts_with(basename($file), AdminTool::AUDIT_LOG),
        ));

        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0] ?? null;
    }

    private function tail(string $file): string
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        fseek($handle, max(0, (int) filesize($file) - self::TAIL_BYTES));
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * Splits the Laravel log format: `[2026-11-25 10:00:00] production.ERROR: message`.
     *
     * @return list<array{time: string, level: string, message: string, context: string}>
     */
    private function entries(string $content): array
    {
        $parts = preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T][\d:.+\-]+\] )/m', $content) ?: [];
        $entries = [];

        foreach ($parts as $part) {
            if (! preg_match('/^\[([^\]]+)\] [\w-]+\.([A-Z]+): (.*)$/s', $part, $match)) {
                continue;
            }

            $lines = explode("\n", rtrim($match[3]));

            $entries[] = [
                'time' => $match[1],
                'level' => $match[2],
                'message' => mb_substr((string) array_shift($lines), 0, 1000),
                'context' => implode("\n", array_slice($lines, 0, self::CONTEXT_LINES)),
            ];
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('The maximum number of entries (1 to 100, default 20). The newest entry comes first.'),
            'include_warnings' => $schema->boolean()->description('Also return WARNING entries.'),
        ];
    }
}
