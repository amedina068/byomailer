<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Support\Instance\ApiTokens;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('create-api-token')]
#[Title('Create an API token')]
#[Description('Creates a product API token for a user. The abilities are read, or read and write (the default). The token opens the REST API and the /mcp server of the account of the user, never the admin server. The response holds the token value one time. The default user is the first owner.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class CreateApiTokenTool extends AdminTool
{
    public function handle(Request $request): Response
    {
        return $this->audited($request, function () use ($request): Response {
            $input = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'user_email' => ['nullable', 'string', 'max:255'],
                'abilities' => ['nullable', 'array'],
                // Product abilities only. An instance_admin token comes from the CLI.
                'abilities.*' => ['string', Rule::in(ApiTokens::PRODUCT_ABILITIES)],
                'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            ]);

            $user = self::userOrOwner($input['user_email'] ?? null);

            if (! $user) {
                return self::userNotFound($input['user_email'] ?? null);
            }

            $expiresAt = isset($input['expires_in_days']) ? CarbonImmutable::now()->addDays((int) $input['expires_in_days']) : null;

            try {
                $token = ApiTokens::create($user, $input['name'], $input['abilities'] ?? ApiTokens::PRODUCT_ABILITIES, $expiresAt);
            } catch (InvalidArgumentException $exception) {
                return self::error('invalid_token_request', $exception->getMessage(), 'Correct the arguments and call the tool again.');
            }

            return self::ok(['token' => ApiTokens::describe($token->accessToken) + ['value' => $token->plainTextToken]]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The name of the token.')->required(),
            'user_email' => $schema->string()->description('The user who owns the token. The default is the first owner.'),
            'abilities' => $schema->array()->items($schema->string()->enum(ApiTokens::PRODUCT_ABILITIES))->description('read, or read and write. The default is read and write.'),
            'expires_in_days' => $schema->integer()->description('Days until the token expires. Without it, the token does not expire.'),
        ];
    }
}
