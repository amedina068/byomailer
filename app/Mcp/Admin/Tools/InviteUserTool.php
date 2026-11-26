<?php

namespace App\Mcp\Admin\Tools;

use App\Actions\InviteTeamMember;
use App\Enums\UserRole;
use App\Mail\UserInviteMail;
use App\Mcp\Admin\AdminTool;
use App\Models\Client;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

#[Name('invite-user')]
#[Title('Invite a user')]
#[Description('Invites a person by email to the team of an account. The default account is the account of the first owner, and the default role is editor. It returns the invitation link, which works for 24 hours, and sends the invitation email through the app mailer when send_email is true (the default). A second call for a pending invitation renews it.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(true)]
class InviteUserTool extends AdminTool
{
    public function handle(Request $request, InviteTeamMember $invite): Response
    {
        return $this->audited($request, function () use ($request, $invite): Response {
            $input = $request->validate([
                'email' => ['required', 'email', 'max:255'],
                'name' => ['nullable', 'string', 'max:255'],
                'account_id' => ['nullable', 'integer', 'min:1'],
                'role' => ['nullable', 'string', Rule::in(UserRole::assignableValues())],
                'send_email' => ['nullable', 'boolean'],
            ]);

            $client = Client::query()->find((int) ($input['account_id'] ?? self::userOrOwner(null)?->client_id));

            if (! $client) {
                return self::error('account_not_found', 'The account does not exist.', 'Call `list-accounts` to read the account ids. Then call this tool again.');
            }

            $role = UserRole::from($input['role'] ?? UserRole::Editor->value);
            $name = $input['name'] ?? Str::of($input['email'])->before('@')->headline()->toString();
            $invitation = $invite->handle($client, $name, $input['email'], $role);

            return self::ok([
                'status' => $invitation['status'],
                'email' => $invitation['user']->email,
                'account' => ['id' => $client->id, 'name' => $client->name],
                'role' => $role->value,
                'invite_url' => $invitation['url'],
                'expires_at' => $invitation['user']->invite_expires_at?->toIso8601String(),
                ...$this->sendEmail($input['send_email'] ?? true, $invitation),
            ]);
        });
    }

    /**
     * @param  array{user: User, url: string, status: string}  $invitation
     * @return array{email_sent: bool, email_error: string|null}
     */
    private function sendEmail(bool $send, array $invitation): array
    {
        if (! $send) {
            return ['email_sent' => false, 'email_error' => null];
        }

        try {
            Mail::to($invitation['user']->email)->send(new UserInviteMail($invitation['user'], $invitation['url']));
        } catch (Throwable $exception) {
            report($exception);

            return ['email_sent' => false, 'email_error' => 'The email was not sent ('.class_basename($exception).'). Give the invite_url to the person.'];
        }

        return ['email_sent' => true, 'email_error' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()->description('The email of the person to invite.')->required(),
            'name' => $schema->string()->description('The name of the person. The default comes from the email.'),
            'account_id' => $schema->integer()->description('The account. The default is the account of the first owner. The ids come from list-accounts.'),
            'role' => $schema->string()->enum(UserRole::assignableValues())->description('The team role. The default is editor.'),
            'send_email' => $schema->boolean()->description('Send the invitation email. The default is true.'),
        ];
    }
}
