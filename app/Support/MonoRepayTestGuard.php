<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use RuntimeException;

class MonoRepayTestGuard
{
    public static function isEnabled(): bool
    {
        return (bool) config('bnpl_mono_repay_test.enabled', false);
    }

    public static function isUserAllowed(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $email = strtolower(trim((string) $user->email));
        $allowedEmails = config('bnpl_mono_repay_test.user_emails', []);
        if ($email !== '' && is_array($allowedEmails) && in_array($email, $allowedEmails, true)) {
            return true;
        }

        $userId = (int) $user->id;
        $allowedIds = config('bnpl_mono_repay_test.user_ids', []);

        return $userId > 0 && is_array($allowedIds) && in_array($userId, $allowedIds, true);
    }

    public static function assertAccess(?User $user): void
    {
        if (! self::isEnabled()) {
            throw new RuntimeException('Mono repayment test lane is disabled.');
        }

        if (! self::isUserAllowed($user)) {
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
