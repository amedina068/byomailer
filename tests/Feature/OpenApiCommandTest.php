<?php

namespace Tests\Feature;

use App\Scopes\ClientScope;
use App\Support\StaticDocs;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiCommandTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/openapi');
        File::ensureDirectoryExists($this->folder);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_it_writes_the_same_document_as_yaml_and_json(): void
    {
        $this->export()->assertSuccessful();

        $yaml = Yaml::parseFile($this->folder.'/openapi.yaml');
        $json = json_decode(File::get($this->folder.'/openapi.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('3.1.2', $yaml['openapi']);
        $this->assertSame('BYOMailer', $yaml['info']['title']);
        $this->assertEquals($json, $yaml);
    }

    public function test_it_uses_the_placeholder_host_and_not_the_app_url(): void
    {
        $rootUrl = url('/');

        $this->export()->assertSuccessful();

        $this->assertSame(
            StaticDocs::PLACEHOLDER_URL.'/api/v1',
            Yaml::parseFile($this->folder.'/openapi.yaml')['servers'][0]['url'],
        );
        $this->assertStringNotContainsString($rootUrl, File::get($this->folder.'/openapi.json'));
        $this->assertSame($rootUrl, url('/'), 'The command must restore the root URL.');
    }

    public function test_the_tags_list_each_tag_that_an_operation_uses(): void
    {
        $this->export()->assertSuccessful();

        $yaml = Yaml::parseFile($this->folder.'/openapi.yaml');

        $used = collect($yaml['paths'])->flatten(1)->pluck('tags.0')->unique()->sort()->values()->all();
        $listed = collect($yaml['tags'])->pluck('name')->sort()->values()->all();

        $this->assertSame($listed, $used);
    }

    public function test_two_runs_write_the_same_bytes_with_one_final_newline(): void
    {
        $this->export()->assertSuccessful();
        $firstYaml = File::get($this->folder.'/openapi.yaml');
        $firstJson = File::get($this->folder.'/openapi.json');

        $this->export()->assertSuccessful();

        $this->assertSame($firstYaml, File::get($this->folder.'/openapi.yaml'));
        $this->assertSame($firstJson, File::get($this->folder.'/openapi.json'));
        $this->assertStringEndsWith("}\n", $firstJson);
        $this->assertStringEndsNotWith("\n\n", $firstJson);
    }

    /**
     * CI copies .env.example, so APP_ENV is production there and the tenant
     * guard only reports. Locally the guard throws. Scramble calls rules()
     * with no tenant, so the guard must not change the document.
     */
    public function test_the_document_does_not_depend_on_the_tenant_guard_mode(): void
    {
        ClientScope::preventQueriesWithoutTenant(false);
        $this->export()->assertSuccessful();
        $reportingJson = File::get($this->folder.'/openapi.json');

        $this->assertFalse(ClientScope::preventsQueriesWithoutTenant(), 'The command must restore the guard mode.');

        ClientScope::preventQueriesWithoutTenant();
        $this->export()->assertSuccessful();

        $this->assertSame($reportingJson, File::get($this->folder.'/openapi.json'));
        $this->assertTrue(ClientScope::preventsQueriesWithoutTenant());
    }

    public function test_the_contact_request_documents_the_email_format(): void
    {
        $this->export()->assertSuccessful();

        $email = Yaml::parseFile($this->folder.'/openapi.yaml')['components']['schemas']['ContactRequest']['properties']['email'];

        $this->assertSame(['type' => 'string', 'format' => 'email'], $email);
    }

    public function test_the_variables_endpoints_document_content_variables_as_a_map_of_strings(): void
    {
        $this->export()->assertSuccessful();

        $paths = Yaml::parseFile($this->folder.'/openapi.yaml')['paths'];

        foreach (['/newsletters/{newsletterId}/variables', '/sequences/{sequenceId}/variables'] as $path) {
            foreach (['get', 'patch'] as $method) {
                $schema = $paths[$path][$method]['responses'][200]['content']['application/json']['schema'];

                $this->assertSame(
                    ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                    $schema['properties']['data']['properties']['content_variables'],
                    "{$method} {$path}",
                );
            }
        }
    }

    public function test_scramble_export_writes_the_same_json_as_the_command_and_no_yaml(): void
    {
        $this->export()->assertSuccessful();

        $this->artisan('scramble:export', ['--path' => $this->folder.'/scramble.json'])->assertSuccessful();

        $this->assertSame(File::get($this->folder.'/openapi.json'), File::get($this->folder.'/scramble.json'));
        $this->assertSame(['openapi.json', 'openapi.yaml', 'scramble.json'], collect(File::files($this->folder))->map->getFilename()->sort()->values()->all());
    }

    public function test_scramble_export_refuses_an_unknown_api(): void
    {
        $this->artisan('scramble:export', ['--api' => 'internal', '--path' => $this->folder.'/scramble.json'])->assertFailed();

        $this->assertFileDoesNotExist($this->folder.'/scramble.json');
    }

    private function export(): mixed
    {
        return $this->artisan('byomailer:openapi', [
            '--yaml' => $this->folder.'/openapi.yaml',
            '--json' => $this->folder.'/openapi.json',
        ]);
    }
}
