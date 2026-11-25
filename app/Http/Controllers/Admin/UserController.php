<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The list of all the users of the instance, for the owner.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $users = User::query()
            ->with('client:id,name')
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';

                $query->where(fn ($query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'client_name' => $user->client?->name,
                'role_label' => $user->role->label(),
                'is_owner' => $user->isOwner(),
                'is_current_user' => $request->user()->is($user),
                'deactivated_at' => $user->deactivated_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/Users', [
            'users' => $users,
            'search' => $search,
        ]);
    }
}
