<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ByoMailerServer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * An AI client calls a tool by the name that a description or the server
 * instructions give. Each tool name in the MCP code must be a name that
 * `tools/list` returns.
 */
class McpToolNameReferencesTest extends TestCase
{
    public function test_each_tool_name_in_the_mcp_code_is_a_real_tool_name(): void
    {
        $names = $this->toolNames();
        $forms = [];

        foreach ($names as $name) {
            $base = Str::beforeLast($name, '-tool');
            $forms[$base] = $name;
            $forms[$base.'-tool'] = $name;
        }

        $wrong = [];

        foreach (File::allFiles(app_path('Mcp')) as $file) {
            preg_match_all('/(?<![\w\/-])[a-z]+(?:-[a-z]+)+(?![\w-])/', $file->getContents(), $matches);

            foreach (array_unique($matches[0]) as $token) {
                if (isset($forms[$token]) && $token !== $forms[$token]) {
                    $wrong[] = $file->getRelativePathname().": {$token} (the tool is {$forms[$token]})";
                }
            }
        }

        $this->assertSame([], $wrong, 'These texts name an MCP tool that does not exist.');
    }

    /**
     * @return list<string>
     */
    private function toolNames(): array
    {
        $tools = (new ReflectionClass(ByoMailerServer::class))->getProperty('tools')->getDefaultValue();

        return array_map(fn (string $tool): string => app($tool)->name(), $tools);
    }
}
