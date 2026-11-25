<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\ManagesTeamMembers;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserArchiveController extends Controller
{
    use ManagesTeamMembers;

    public function store(Request $request, User $teamMember): RedirectResponse
    {
        $this->ensureAdmin($request->user());
        $this->ensureSameClient($request->user(), $teamMember);

        if ($request->user()->is($teamMember)) {
            throw ValidationException::withMessages([
                'member' => 'You cannot archive yourself.',
            ]);
        }

        if ($teamMember->archived_at !== null) {
            return redirect()->route('team.index')->with('success', 'Member already archived.');
        }

        if ($teamMember->isAdmin()) {
            $this->ensureClientKeepsAdmin($teamMember, 'member');
        }

        DB::transaction(function () use ($teamMember): void {
            $teamMember->forceFill(['archived_at' => now()])->save();
            $teamMember->tokens()->delete();
        });

        return redirect()->route('team.index')->with('success', 'Team member archived.');
    }

    public function destroy(Request $request, User $teamMember): RedirectResponse
    {
        $this->ensureAdmin($request->user());
        $this->ensureSameClient($request->user(), $teamMember);

        if ($teamMember->archived_at === null) {
            return redirect()->route('team.index')->with('success', 'Team member restored.');
        }

        $teamMember->forceFill(['archived_at' => null])->save();

        return redirect()->route('team.index')->with('success', 'Team member restored.');
    }
}
