<?php

namespace Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ExportPostmanCommandTest extends TestCase
{
    private string $folder;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $convertedSpec = null;

    private ?string $randomScript = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/postman');
        File::ensureDirectoryExists($this->folder);

        File::put($this->folder.'/openapi.yaml', Yaml::dump([
            'openapi' => '3.1.0',
            'info' => ['title' => 'BYOMailer', 'version' => '1.0.0'],
            'servers' => [['url' => 'https://byomailer.example/api/v1']],
            'paths' => ['/contacts' => ['get' => ['tags' => ['Contacts'], 'responses' => ['200' => ['description' => 'OK']]]]],
        ], 10, 2));

        Process::fake(function (PendingProcess $process) {
            $this->convertedSpec = json_decode(File::get($process->path.'/openapi.json'), true);
            $this->randomScript = File::get($process->path.'/fixed-random.cjs');

            File::put($process->path.'/collection.json', json_encode($this->converterOutput()));

            return Process::result();
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_it_runs_the_pinned_converter_with_the_tag_folders_and_a_fixed_random(): void
    {
        $this->export()->assertSuccessful();

        Process::assertRan(function (PendingProcess $process): bool {
            $command = implode(' ', $process->command);

            return str_contains($command, 'npx --yes openapi-to-postmanv2@6.4.2')
                && str_contains($command, 'folderStrategy=Tags')
                && str_contains($command, 'enableOptionalParameters=false')
                && str_contains($process->environment['NODE_OPTIONS'] ?? '', '--require '.$process->path.'/fixed-random.cjs');
        });
    }

    /**
     * A seeded sequence is not enough: a change in one operation moves the
     * sequence and changes the examples of each later operation.
     */
    public function test_each_random_number_of_the_converter_is_zero_so_an_example_depends_on_its_own_schema_only(): void
    {
        $this->export()->assertSuccessful();

        $this->assertSame("Math.random = () => 0;\n", $this->randomScript);
    }

    public function test_two_runs_write_the_same_bytes_with_one_final_newline(): void
    {
        $this->export()->assertSuccessful();
        $first = File::get($this->folder.'/postman_collection.json');

        $this->export()->assertSuccessful();
        $second = File::get($this->folder.'/postman_collection.json');

        $this->assertSame($first, $second);
        $this->assertStringEndsWith("}\n", $second);
        $this->assertStringEndsNotWith("\n\n", $second);
    }

    public function test_it_converts_paths_that_start_at_the_instance_url(): void
    {
        $this->export()->assertSuccessful();

        $this->assertSame([['url' => 'https://app.example.com']], $this->convertedSpec['servers']);
        $this->assertSame(['/api/v1/contacts'], array_keys($this->convertedSpec['paths']));
    }

    public function test_the_collection_uses_the_base_url_and_token_variables_with_bearer_auth(): void
    {
        $this->export()->assertSuccessful();

        $collection = $this->readJson('postman_collection.json');

        $this->assertSame([
            'type' => 'bearer',
            'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
        ], $collection['auth']);
        $this->assertSame([
            ['key' => 'baseUrl', 'value' => 'https://app.example.com', 'type' => 'string'],
            ['key' => 'token', 'value' => 'YOUR_API_TOKEN', 'type' => 'string'],
        ], $collection['variable']);
    }

    public function test_the_collection_has_no_random_ids_so_the_output_is_stable(): void
    {
        $this->export()->assertSuccessful();

        $contents = File::get($this->folder.'/postman_collection.json');

        $this->assertStringNotContainsString('_postman_id', $contents);
        $this->assertStringNotContainsString('"id"', $contents);
        $this->assertSame('List contacts', $this->readJson('postman_collection.json')['item'][0]['item'][0]['name']);
    }

    public function test_the_environment_has_the_same_variables_with_placeholder_values(): void
    {
        $this->export()->assertSuccessful();

        $this->assertSame([
            'name' => 'BYOMailer',
            'values' => [
                ['key' => 'baseUrl', 'value' => 'https://app.example.com', 'type' => 'default', 'enabled' => true],
                ['key' => 'token', 'value' => 'YOUR_API_TOKEN', 'type' => 'secret', 'enabled' => true],
            ],
            '_postman_variable_scope' => 'environment',
        ], $this->readJson('postman_environment.json'));
    }

    public function test_it_fails_and_writes_nothing_when_the_converter_fails(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Invalid spec', exitCode: 1)]);

        $this->export()->assertFailed();

        $this->assertFileDoesNotExist($this->folder.'/postman_collection.json');
        $this->assertFileDoesNotExist($this->folder.'/postman_environment.json');
    }

    private function export(): mixed
    {
        return $this->artisan('byomailer:postman', [
            '--spec' => $this->folder.'/openapi.yaml',
            '--collection' => $this->folder.'/postman_collection.json',
            '--environment' => $this->folder.'/postman_environment.json',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $file): array
    {
        return json_decode(File::get($this->folder.'/'.$file), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function converterOutput(): array
    {
        return [
            'info' => ['_postman_id' => 'b49ff51f-4971-41d3-af7c-2bd49726b6e6', 'name' => 'BYOMailer'],
            'item' => [[
                'name' => 'Contacts',
                'item' => [[
                    'id' => 'd6291294-d998-4a57-aa20-fbe64578ad99',
                    'name' => 'List contacts',
                    'request' => ['method' => 'GET', 'url' => ['raw' => '{{baseUrl}}/api/v1/contacts']],
                    'response' => [['id' => 'f60348c4-d3a4-441f-917d-f72f0b673eee', 'name' => 'OK', 'code' => 200]],
                ]],
            ]],
            'auth' => ['type' => 'bearer', 'bearer' => [['type' => 'any', 'value' => '{{bearerToken}}', 'key' => 'token']]],
            'event' => [],
            'variable' => [['key' => 'baseUrl', 'value' => 'https://app.example.com']],
        ];
    }
}
