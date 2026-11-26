<?php

namespace App\Mcp\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * The base of every tool of the instance-admin MCP server.
 *
 * A tool runs its work inside `audited()`. It writes one line to the
 * `mcp-admin` log channel for each call: the tool, the transport, the caller
 * and the arguments, with the secret values removed. A log channel needs no
 * migration, and the instance calls do not belong to one account.
 *
 * A destructive tool calls `preview()` first. Without `confirm: true` it
 * returns the preview and changes nothing.
 */
abstract class AdminTool extends Tool
{
    public const AUDIT_LOG = 'mcp-admin';

    /** Argument names whose values the audit line never stores. */
    private const SECRET_KEYS = '/^(password|secret|token|plain_text|api_key|access_key|secret_key)$/i';

    /**
     * @param  callable(): Response  $callback
     */
    protected function audited(Request $request, callable $callback): Response
    {
        $ok = false;

        try {
            $response = $callback();
            $ok = ! $response->isError();

            return $response;
        } catch (ValidationException $exception) {
            return self::error('invalid_arguments', collect($exception->errors())->flatten()->implode(' '), 'Correct the arguments and call the tool again.');
        } finally {
            $this->audit($request, $ok);
        }
    }

    /**
     * Returns the preview response when the call has no `confirm: true`, and
     * null when the tool can act.
     *
     * @param  array<string, mixed>  $preview
     */
    protected function preview(Request $request, array $preview): ?Response
    {
        if ($request->get('confirm') === true) {
            return null;
        }

        return self::ok([
            'preview' => $preview,
            'confirm_required' => true,
            'next' => 'Nothing changed. Show this preview to the human. If the human agrees, call `'.$this->name().'` again with the same arguments and `confirm: true`.',
        ]);
    }

    /**
     * The user with this email, or the first owner when the email is empty.
     */
    protected static function userOrOwner(?string $email): ?User
    {
        return $email
            ? User::query()->where('email', $email)->first()
            : User::query()->where('is_owner', true)->orderBy('id')->first();
    }

    protected static function userNotFound(?string $email): Response
    {
        return $email
            ? self::error('user_not_found', "No user has the email `{$email}`.", 'Call `list-users` to read the emails. Then call this tool again.')
            : self::error('no_owner', 'This instance has no owner.', 'Run `php artisan byomailer:install` on the server. An MCP tool cannot create the owner.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function ok(array $data): Response
    {
        return Response::text((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected static function error(string $code, string $message, string $fix, array $details = []): Response
    {
        $error = ['code' => $code, 'message' => $message, 'fix' => $fix];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return Response::error((string) json_encode(['error' => $error], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function audit(Request $request, bool $ok): void
    {
        $user = $request->user();

        try {
            Log::channel(self::AUDIT_LOG)->info('mcp-admin call', [
                'tool' => $this->name(),
                'arguments' => self::redactArguments($request->all()),
                'transport' => $user ? 'http' : 'stdio',
                'user' => $user instanceof User ? ['id' => $user->id, 'email' => $user->email] : null,
                'ok' => $ok,
            ]);
        } catch (Throwable $exception) {
            // The result reaches the caller even when the log cannot be written.
            report($exception);
        }
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return array<array-key, mixed>
     */
    private static function redactArguments(array $arguments): array
    {
        foreach ($arguments as $name => $value) {
            if (preg_match(self::SECRET_KEYS, (string) $name)) {
                $arguments[$name] = '[redacted]';
            } elseif (is_array($value)) {
                $arguments[$name] = self::redactArguments($value);
            }
        }

        return $arguments;
    }
}
