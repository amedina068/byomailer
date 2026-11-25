<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * BYOMailer could not get AWS credentials for an account. The message is safe
 * to show to the user. It never contains a key, a token or an AWS response.
 */
class AwsCredentialsException extends RuntimeException
{
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'error' => $this->getMessage()], 422);
        }

        return back()->with('error', $this->getMessage());
    }
}
