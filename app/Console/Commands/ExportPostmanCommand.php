<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Makes the Postman collection and the Postman environment from the OpenAPI document.
 *
 * The converter runs with npx, so it is not a dependency of the project. Each
 * request URL starts at `{{baseUrl}}`, the URL of the instance, so a user
 * sets one URL and one token.
 *
 * The converter makes example values with `Math.random()` and random IDs. The
 * command gives the converter a `Math.random()` that always returns 0 and
 * removes the IDs, so the output is the same on each run.
 */
class ExportPostmanCommand extends Command
{
    /**
     * A new converter version changes the output. Change it on purpose only.
     */
    public const CONVERTER = 'openapi-to-postmanv2@6.4.2';

    public const BASE_URL = 'https://app.example.com';

    public const TOKEN = 'YOUR_API_TOKEN';

    private const CONVERTER_OPTIONS = [
        'folderStrategy=Tags',
        'requestParametersResolution=Example',
        'exampleParametersResolution=Example',
        'enableOptionalParameters=false',
        'includeAuthInfoInExample=false',
    ];

    /**
     * Node loads this file before the converter. Each random number is 0, so
     * each example depends on its own schema only: an enum gets its first
     * value. A seeded sequence is not enough, because a change in one
     * operation moves the sequence and changes the examples of all later
     * operations.
     */
    private const FIXED_RANDOM = <<<'JS'
        Math.random = () => 0;
        JS;

    protected $signature = 'byomailer:postman
        {--spec=docs/api/openapi.yaml : The OpenAPI document to convert}
        {--collection=docs/api/postman_collection.json : The path of the collection}
        {--environment=docs/api/postman_environment.json : The path of the environment}';

    protected $description = 'Make the Postman collection and environment from the OpenAPI document';

    public function handle(): int
    {
        $folder = storage_path('framework/cache/postman-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($folder);

        try {
            File::put($folder.'/openapi.json', json_encode($this->withInstancePaths(Yaml::parseFile($this->path('spec'))), JSON_UNESCAPED_SLASHES));
            File::put($folder.'/fixed-random.cjs', self::FIXED_RANDOM."\n");

            $result = Process::path($folder)->timeout(300)->env([
                'NODE_OPTIONS' => trim(getenv('NODE_OPTIONS').' --require '.$folder.'/fixed-random.cjs'),
            ])->run([
                'npx', '--yes', self::CONVERTER,
                '--spec', 'openapi.json',
                '--output', 'collection.json',
                '--options', implode(',', self::CONVERTER_OPTIONS),
            ]);

            if ($result->failed()) {
                $this->components->error('The converter failed: '.trim($result->errorOutput() ?: $result->output()));

                return self::FAILURE;
            }

            $collection = json_decode(File::get($folder.'/collection.json'), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            File::deleteDirectory($folder);
        }

        $this->writeJson('collection', $this->tidy($collection));
        $this->writeJson('environment', $this->environment());

        return self::SUCCESS;
    }

    /**
     * Moves the path prefix of the server URL (`/api/v1`) into each path, so
     * that `{{baseUrl}}` is the URL of the instance only.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function withInstancePaths(array $spec): array
    {
        $prefix = rtrim((string) parse_url($spec['servers'][0]['url'] ?? '', PHP_URL_PATH), '/');

        $spec['servers'] = [['url' => self::BASE_URL]];
        $spec['paths'] = collect($spec['paths'])->mapWithKeys(fn (array $operations, string $path) => [$prefix.$path => $operations])->all();

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $collection
     * @return array<string, mixed>
     */
    private function tidy(array $collection): array
    {
        unset($collection['info']['_postman_id']);

        $collection['item'] = $this->withoutIds($collection['item']);
        $collection['auth'] = [
            'type' => 'bearer',
            'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
        ];
        $collection['variable'] = [
            ['key' => 'baseUrl', 'value' => self::BASE_URL, 'type' => 'string'],
            ['key' => 'token', 'value' => self::TOKEN, 'type' => 'string'],
        ];

        return $collection;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function withoutIds(array $items): array
    {
        return array_map(function (array $item): array {
            unset($item['id']);

            if (isset($item['item'])) {
                $item['item'] = $this->withoutIds($item['item']);
            }

            if (isset($item['response'])) {
                $item['response'] = $this->withoutIds($item['response']);
            }

            return $item;
        }, $items);
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(): array
    {
        return [
            'name' => 'BYOMailer',
            'values' => [
                ['key' => 'baseUrl', 'value' => self::BASE_URL, 'type' => 'default', 'enabled' => true],
                ['key' => 'token', 'value' => self::TOKEN, 'type' => 'secret', 'enabled' => true],
            ],
            '_postman_variable_scope' => 'environment',
        ];
    }

    /**
     * @param  array<string, mixed>  $contents
     */
    private function writeJson(string $option, array $contents): void
    {
        $path = $this->path($option);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

        $this->components->info("Wrote {$path}.");
    }

    private function path(string $option): string
    {
        $path = (string) $this->option($option);

        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
