<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CreateApiTokenRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('settings/ApiTokens', [
            'tokens' => $this->tokenRows($request->user()),
            'newToken' => null,
            'expirationDays' => CreateApiTokenRequest::EXPIRATION_DAYS,
        ]);
    }

    public function store(CreateApiTokenRequest $request): Response
    {
        $days = $request->validated('expires_in_days');

        $token = $request->user()->createToken(
            $request->validated('name'),
            $request->abilities(),
            $days === null ? null : now()->addDays((int) $days),
        );

        return Inertia::render('settings/ApiTokens', [
            'tokens' => $this->tokenRows($request->user()),
            'newToken' => $token->plainTextToken,
            'expirationDays' => CreateApiTokenRequest::EXPIRATION_DAYS,
        ]);
    }

    public function destroy(Request $request, int $tokenId): RedirectResponse
    {
        $request->user()->tokens()->where('id', $tokenId)->delete();

        return to_route('api-tokens.index');
    }

    /**
     * @return Collection<int, array{id: int, name: string, access: string, expires_at: ?string, last_used_at: ?string, created_at: string}>
     */
    private function tokenRows(User $user): Collection
    {
        return $user->tokens()->oldest('id')->get()->map(fn (PersonalAccessToken $token): array => [
            'id' => $token->id,
            'name' => $token->name,
            'access' => $this->accessOf($token),
            'expires_at' => $token->expires_at?->toDateString(),
            'last_used_at' => $token->last_used_at?->diffForHumans(),
            'created_at' => $token->created_at->diffForHumans(),
        ]);
    }

    /**
     * Tokens made before the abilities existed have `*`. They show as read
     * and write, because that is the access that they have.
     */
    private function accessOf(PersonalAccessToken $token): string
    {
        if ($token->can('write')) {
            return $token->can('read') ? 'read_write' : 'write';
        }

        return $token->can('read') ? 'read' : 'none';
    }
}
