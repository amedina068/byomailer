<?php

namespace App\Mcp\Admin\Tools;

use App\Enums\UserRole;
use App\Mail\SesTestMail;
use App\Mcp\Admin\AdminTool;
use App\Mcp\Admin\RedactsSecrets;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\UserMailerFactory;
use App\Support\Instance\SesStatus;
use App\Support\Tenant;
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
use Throwable;

#[Name('send-test-email')]
#[Title('Send a test email through Amazon SES')]
#[Description('Sends one test email through the Amazon SES keys of an account, from a verified sender identity of that account. The default account is the account of the first owner, and the default recipient is the first owner. When the account has no AWS keys or no verified identity, the tool returns an error with the fix. In the SES sandbox, SES delivers only to verified addresses.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(true)]
class SendTestEmailTool extends AdminTool
{
    use RedactsSecrets;

    public function handle(Request $request, SesStatus $ses, UserMailerFactory $mailers): Response
    {
        return $this->audited($request, function () use ($request, $ses, $mailers): Response {
            $input = $request->validate([
                'to' => ['nullable', 'email', 'max:255'],
                'account_id' => ['nullable', 'integer', 'min:1'],
                'from' => ['nullable', 'email', 'max:255'],
            ]);

            $owner = self::userOrOwner(null);
            $clientId = (int) ($input['account_id'] ?? $owner?->client_id);
            $sender = $this->senderOf($clientId, $owner);

            if (! $sender) {
                return $owner ? self::error('account_not_found', "No account with users has the id {$clientId}.", 'Call `list-accounts` to read the account ids.') : self::userNotFound(null);
            }

            $credential = $ses->credentials($clientId)->first();

            if (! $credential) {
                return self::error('ses_not_configured', 'This account has no AWS keys for Amazon SES. BYOMailer sends email only through SES.', 'Log in as a team admin of the account, open '.$this->url('/settings/email-credentials').', and enter the access key of an IAM user that can use SES.');
            }

            $identity = $this->identity($clientId, $input['from'] ?? null);

            if (! $identity) {
                return self::error('no_verified_identity', isset($input['from']) ? "`{$input['from']}` is not a verified sender identity of this account." : 'This account has no verified sender identity.', 'Open '.$this->url('/settings/identities').', add the sending domain, and publish the DNS records. Then call `get-ses-status` to see the verified emails.');
            }

            $to = $input['to'] ?? $sender->email;

            try {
                Tenant::run($clientId, fn () => $mailers->mailerFor($sender)->to($to)->send(new SesTestMail($identity->email, $identity->name ?: 'BYOMailer', (string) config('app.url'))));
            } catch (Throwable $exception) {
                return self::error('ses_send_failed', 'SES did not send the email: '.$this->redact(class_basename($exception).': '.(strtok($exception->getMessage(), "\n") ?: '')), 'Read the error. In the SES sandbox, verify the recipient address too. Make sure that the region is correct and that the IAM user can call ses:SendEmail and ses:SendRawEmail.', ['to' => $to, 'from' => $identity->email]);
            }

            return self::ok([
                'sent' => true,
                'to' => $to,
                'from' => $identity->email,
                'account' => ['id' => $clientId, 'name' => $credential->account_name],
                'region' => $credential->region,
                'transport' => config('mail.use_ses_api') ? 'ses' : (string) config('mail.default'),
                'note' => $credential->production_access_enabled ? null : 'The account can be in the SES sandbox. SES then delivers only to verified addresses.',
            ]);
        });
    }

    /**
     * The user whose account sends: the owner when the owner is in the
     * account, or else the first team admin of the account.
     */
    private function senderOf(int $clientId, ?User $owner): ?User
    {
        if ($owner?->client_id === $clientId) {
            return $owner;
        }

        return User::query()
            ->where('client_id', $clientId)
            ->orderByRaw('case when role = ? then 0 else 1 end', [UserRole::Admin->value])
            ->orderBy('id')
            ->first();
    }

    private function identity(int $clientId, ?string $from): ?EmailIdentity
    {
        return EmailIdentity::withoutGlobalScopes()
            ->where('client_id', $clientId)
            ->where('identity_status', 'verified')
            ->when($from !== null, fn ($query) => $query->where('email', $from))
            ->orderBy('id')
            ->first();
    }

    private function url(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'to' => $schema->string()->description('The address. The default is the email of the first owner.'),
            'account_id' => $schema->integer()->description('The account that sends. The default is the account of the first owner.'),
            'from' => $schema->string()->description('A verified sender email of the account. The default is the first verified identity.'),
        ];
    }
}
