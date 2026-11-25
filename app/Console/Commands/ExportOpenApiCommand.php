<?php

namespace App\Console\Commands;

use App\Scopes\ClientScope;
use App\Support\StaticDocs;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes the OpenAPI document that Scramble makes from the API routes.
 *
 * The output must be the same on each computer, because CI runs
 * `composer api-docs` and fails when the files change. So the command
 * uses the placeholder host, not APP_URL. It also refuses queries with
 * no tenant, as in local and test mode. Scramble calls rules() of each
 * FormRequest with no tenant, and APP_ENV must not change the result.
 */
class ExportOpenApiCommand extends Command
{
    protected $signature = 'byomailer:openapi
        {--yaml=docs/api/openapi.yaml : The path of the YAML file. An empty value writes no YAML file}
        {--json=public/docs/openapi.json : The path of the JSON file that the documentation pages serve. An empty value writes no JSON file}';

    protected $description = 'Write the OpenAPI document of the API as YAML and JSON';

    public function handle(Generator $generator): int
    {
        $document = $this->withFixedEnvironment(
            fn (): array => $generator(Scramble::getGeneratorConfig(Scramble::DEFAULT_API))
        );

        if ($this->option('yaml')) {
            $this->write($this->option('yaml'), Yaml::dump($document, 20, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_OBJECT_AS_MAP));
        }

        if ($this->option('json')) {
            $this->write($this->option('json'), json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        }

        return self::SUCCESS;
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function withFixedEnvironment(callable $callback): array
    {
        $preventedQueriesWithoutTenant = ClientScope::preventsQueriesWithoutTenant();

        URL::forceRootUrl(StaticDocs::PLACEHOLDER_URL);
        URL::forceScheme('https');
        ClientScope::preventQueriesWithoutTenant();

        try {
            return $callback();
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
            ClientScope::preventQueriesWithoutTenant($preventedQueriesWithoutTenant);
        }
    }

    private function write(string $path, string $contents): void
    {
        $path = str_starts_with($path, '/') ? $path : base_path($path);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);

        $this->components->info("Wrote {$path}.");
    }
}
