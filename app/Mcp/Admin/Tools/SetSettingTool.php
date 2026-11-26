<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Support\Registration;
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

#[Name('set-setting')]
#[Title('Set a setting')]
#[Description('Changes one setting from the allowlist that get-settings returns. Today the allowlist is registration_mode (open or invite_only). Settings from the environment cannot change here.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class SetSettingTool extends AdminTool
{
    /**
     * The settings that this tool can change, with the allowed values. A
     * setting that is not in this list stays in the admin area or in the
     * environment.
     */
    public const ALLOWED = [
        Registration::SETTING => Registration::MODES,
    ];

    public function handle(Request $request): Response
    {
        return $this->audited($request, function () use ($request): Response {
            $input = $request->validate([
                'key' => ['required', 'string'],
                'value' => ['required', 'string'],
            ]);

            if (! array_key_exists($input['key'], self::ALLOWED)) {
                return self::error('setting_not_allowed', "The setting `{$input['key']}` cannot change through this tool.", 'Call `get-settings` to read the settings that can change.', ['allowed' => array_keys(self::ALLOWED)]);
            }

            if (! in_array($input['value'], self::ALLOWED[$input['key']], true)) {
                return self::error('invalid_setting_value', "The value `{$input['value']}` is not allowed for `{$input['key']}`.", 'Use one of the values in `details.allowed`.', ['allowed' => self::ALLOWED[$input['key']]]);
            }

            $before = Registration::mode();
            Registration::setMode($input['value']);

            return self::ok([
                'key' => $input['key'],
                'previous' => $before,
                'value' => $input['value'],
                'status' => $before === $input['value'] ? 'unchanged' : 'changed',
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->enum(array_keys(self::ALLOWED))->description('The setting to change.')->required(),
            'value' => $schema->string()->description('The new value. get-settings lists the allowed values.')->required(),
        ];
    }
}
