<?php

namespace App\Support;

use Illuminate\Http\Request;
use RuntimeException;

class MonoRepayTestGuard
{
    public static function isEnabled(): bool
    {
        return (bool) config('bnpl_mono_repay_test.enabled', false);
    }

    public static function isUserAllowed(?int $userId): bool
    {
        if ($userId === null || $userId <= 0) {
            return false;
        }

        $allowed = config('bnpl_mono_repay_test.user_ids', []);

        return is_array($allowed) && in_array($userId, $allowed, true);
    }

    public static function assertAccess(?int $userId): void
    {
        if (! self::isEnabled()) {
            throw new RuntimeException('Mono repayment test lane is disabled.');
        }

        if (! self::isUserAllowed($userId)) {
            throw new RuntimeException('Your account is not enabled for Mono repayment testing.');
        }
    }

    public static function assertSecret(Request $request): void
    {
        $expected = (string) config('bnpl_mono_repay_test.secret', '');
        if ($expected === '') {
            throw new RuntimeException('Mono repayment test secret is not configured on the server.');
        }

        $provided = (string) (
            $request->header('X-Mono-Repay-Test-Secret')
            ?? $request->input('test_secret')
            ?? $request->query('token')
            ?? ''
        );

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            throw new RuntimeException('Invalid test access token.');
        }
    }
}
