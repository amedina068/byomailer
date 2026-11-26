<?php

namespace App\Http\Controllers\Settings;

use App\Actions\InviteTeamMember;
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
use Illuminate\Support\Facades\Mail;
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

    public function store(InviteTeamMemberRequest $request, InviteTeamMember $invite): RedirectResponse
    {
        $this->ensureAdmin($request->user());

        $invitation = $invite->handle(
            $request->user()->client,
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            UserRole::from($request->string('role')->toString()),
        );

        Mail::to($invitation['user']->email)->send(new UserInviteMail($invitation['user'], $invitation['url']));

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
