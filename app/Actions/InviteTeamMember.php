<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invites a person to the team of an account (client).
 *
 * The invitation is a user row with `invite_expires_at` and a signed link
 * that works for 24 hours. A second invitation to a pending person renews
 * the row and the link. Settings, Team and the `invite-user` tool of the
 * instance-admin MCP server use this action. The caller sends the email.
 */
class InviteTeamMember
{
    public const EXPIRES_IN_HOURS = 24;

    /**
     * @return array{user: User, url: string, status: 'created'|'renewed'}
     *
     * @throws ValidationException when the email belongs to a member or to another account
     */
    public function handle(Client $client, string $name, string $email, UserRole $role): array
    {
        $email = Str::lower($email);
        $expiresAt = now()->addHours(self::EXPIRES_IN_HOURS);
        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser !== null) {
            $this->ensureCanRenew($existingUser, $client);

            $existingUser->forceFill([
                'name' => $name,
                'email' => $email,
                'invite_expires_at' => $expiresAt,
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => null,
                'role' => $role->value,
            ])->save();

            $invitee = $existingUser->fresh();
            $status = 'renewed';
        } else {
            $invitee = $client->users()->create([
                'name' => $name,
                'email' => $email,
                'role' => $role->value,
                'invite_expires_at' => $expiresAt,
                'password' => Hash::make(Str::random(40)),
            ]);
            $status = 'created';
        }

        $url = URL::temporarySignedRoute('invites.show', $expiresAt, ['user' => $invitee->uuid]);

        return ['user' => $invitee, 'url' => $url, 'status' => $status];
    }

    private function ensureCanRenew(User $user, Client $client): void
    {
        if ($user->client_id !== $client->id) {
            throw ValidationException::withMessages([
                'email' => 'This email address is already in use.',
            ]);
        }

        if ($user->archived_at !== null) {
            throw ValidationException::withMessages([
                'email' => 'This user is archived. Restore them instead of inviting again.',
            ]);
        }

        if ($user->invite_expires_at === null) {
            throw ValidationException::withMessages([
                'email' => 'This user is already part of your team.',
            ]);
        }
    }
}
