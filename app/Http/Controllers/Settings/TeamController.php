<?php

namespace App\Http\Controllers\Settings;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\ManagesTeamMembers;
use App\Http\Requests\Settings\InviteTeamMemberRequest;
use App\Http\Requests\Settings\UpdateTeamMemberRoleRequest;
use App\Http\Resources\UserResource;
use App\Mail\UserInviteMail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    use ManagesTeamMembers;

    public function index(Request $request): Response
    {
        $assignableRoles = collect(UserRole::cases())
            ->reject(fn (UserRole $role): bool => $role === UserRole::Invited);

        $teamMembers = $request->user()
            ->client
            ->users()
            ->orderBy('created_at')
            ->get();

        return Inertia::render('settings/Team', [
            'teamMembers' => $teamMembers
                ->map(fn (User $member) => UserResource::make($member)->resolve($request))
                ->all(),
            'availableRoles' => $assignableRoles
                ->map(fn (UserRole $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ])->values(),
            'canManageTeam' => $request->user()->isAdmin(),
            'inviteExpiresInHours' => 24,
        ]);
    }

    public function store(InviteTeamMemberRequest $request): RedirectResponse
    {
        $this->ensureAdmin($request->user());

        $client = $request->user()->client;
        $email = Str::lower($request->string('email')->toString());
        $expiresAt = now()->addDay();
        $intendedRole = UserRole::from($request->string('role')->toString());

        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser !== null) {
            if ($existingUser->client_id !== $client->id) {
                throw ValidationException::withMessages([
                    'email' => 'This email address is already in use.',
                ]);
            }

            if ($existingUser->archived_at !== null) {
                throw ValidationException::withMessages([
                    'email' => 'This user is archived. Restore them instead of inviting again.',
                ]);
            }

            if ($existingUser->invite_expires_at === null) {
                throw ValidationException::withMessages([
                    'email' => 'This user is already part of your team.',
                ]);
            }

            $existingUser->forceFill([
                'name' => $request->string('name')->toString(),
                'email' => $email,
                'invite_expires_at' => $expiresAt,
                'password' => Hash::make(Str::random(40)),
                'email_verified_at' => null,
                'role' => $intendedRole->value,
            ])->save();

            $invitee = $existingUser->fresh();
        } else {
            $invitee = $client->users()->create([
                'name' => $request->string('name')->toString(),
                'email' => $email,
                'role' => $intendedRole->value,
                'invite_expires_at' => $expiresAt,
                'password' => Hash::make(Str::random(40)),
            ]);
        }

        $inviteUrl = URL::temporarySignedRoute(
            'invites.show',
            $expiresAt,
            ['user' => $invitee->uuid]
        );

        Mail::to($invitee->email)->send(new UserInviteMail($invitee, $inviteUrl));

        return redirect()->route('team.index')->with('success', 'Invitation sent.');
    }

    public function update(UpdateTeamMemberRoleRequest $request, User $teamMember): RedirectResponse
    {
        $this->ensureAdmin($request->user());
        $this->ensureSameClient($request->user(), $teamMember);

        if ($teamMember->invite_expires_at !== null) {
            throw ValidationException::withMessages([
                'role' => 'Pending invitations cannot be reassigned.',
            ]);
        }

        $newRole = UserRole::from($request->string('role')->toString());

        if ($teamMember->role === UserRole::Admin && $newRole !== UserRole::Admin) {
            $this->ensureClientKeepsAdmin($teamMember);
        }

        $teamMember->forceFill(['role' => $newRole->value])->save();

        return redirect()->route('team.index')->with('success', 'Role updated.');
    }
}
