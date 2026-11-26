<?php

namespace App\Support\Instance;

/**
 * The result of one doctor check: `{id, status, message, fix}`.
 *
 * A `fail` means that the instance cannot do its main job. A `warn` means
 * that a part works badly, that a background process is missing, or that a
 * setup step is not done yet. The fix is empty only when the status is `ok`.
 */
final class DoctorCheck
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /**
     * @return array{id: string, status: string, message: string, fix: string}
     */
    public static function ok(string $id, string $message): array
    {
        return self::make($id, self::OK, $message, '');
    }

    /**
     * @return array{id: string, status: string, message: string, fix: string}
     */
    public static function warn(string $id, string $message, string $fix): array
    {
        return self::make($id, self::WARN, $message, $fix);
    }

    /**
     * @return array{id: string, status: string, message: string, fix: string}
     */
    public static function fail(string $id, string $message, string $fix): array
    {
        return self::make($id, self::FAIL, $message, $fix);
    }

    /**
     * @return array{id: string, status: string, message: string, fix: string}
     */
    private static function make(string $id, string $status, string $message, string $fix): array
    {
        return ['id' => $id, 'status' => $status, 'message' => $message, 'fix' => $fix];
    }
}
