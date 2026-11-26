<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Support\Instance\SesStatus;
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

#[Name('get-ses-status')]
#[Title('Get the Amazon SES status')]
#[Description('Returns the Amazon SES state of each account, from the values that BYOMailer stores: AWS keys set or not (never the keys), region, send quota and production access (read each hour by the scheduler), verified sender identities, and the event setup. It also returns whether the SQS queue for SES events is set, and the missing environment variables. The tool makes no AWS call. Give account_id to read one account.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetSesStatusTool extends AdminTool
{
    public function handle(Request $request, SesStatus $ses): Response
    {
        return $this->audited($request, function () use ($request, $ses): Response {
            $input = $request->validate([
                'account_id' => ['nullable', 'integer', 'min:1'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            ]);

            $accountId = isset($input['account_id']) ? (int) $input['account_id'] : null;
            $accounts = $ses->accounts($accountId, (int) ($input['limit'] ?? 100));

            if ($accountId !== null && $accounts === []) {
                return self::error('account_not_found', "No account has the id {$accountId}.", 'Call `list-accounts` to read the account ids.');
            }

            return self::ok([
                'accounts' => $accounts,
                'events' => $ses->events(),
                'credentials_url' => rtrim((string) config('app.url'), '/').'/settings/email-credentials',
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->integer()->description('Read only this account. The ids come from list-accounts.'),
            'limit' => $schema->integer()->description('The maximum number of accounts (1 to 500, default 100).'),
        ];
    }
}
