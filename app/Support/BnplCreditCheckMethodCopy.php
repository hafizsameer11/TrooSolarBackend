<?php

namespace App\Support;

use App\Models\BnplSettings;

class BnplCreditCheckMethodCopy
{
    public static function defaults(): array
    {
        return [
            'intro' => 'Choose how you would like to complete your credit check.',
            'continue_label' => 'Continue',
            'unavailable_label' => 'Currently unavailable',
            'residential' => [
                'auto_enabled' => true,
                'auto_title' => 'Connect your bank (Recommended)',
                'auto_description' => 'Link your account with Mono, pay the verification fee, then we run the credit check automatically.',
                'manual_enabled' => true,
                'manual_title' => 'Manual review',
                'manual_description' => 'Pay the verification fee first, then upload your bank statement and selfie.',
            ],
            'sme' => [
                'auto_enabled' => true,
                'auto_title' => 'Connect your bank (Recommended)',
                'auto_description' => 'Link your business account with Mono, pay the verification fee, then we run the credit check automatically.',
                'manual_enabled' => true,
                'manual_title' => 'Manual review',
                'manual_description' => 'Pay the verification fee first, then upload your bank statement and selfie.',
            ],
        ];
    }

    public static function fromSettings(?BnplSettings $settings = null): array
    {
        $defaults = self::defaults();
        $settings = $settings ?? BnplSettings::get();

        return [
            'intro' => self::textOrDefault($settings->credit_check_intro ?? null, $defaults['intro']),
            'continue_label' => self::textOrDefault($settings->credit_check_continue_label ?? null, $defaults['continue_label']),
            'unavailable_label' => self::textOrDefault($settings->credit_check_unavailable_label ?? null, $defaults['unavailable_label']),
            'residential' => [
                'auto_enabled' => self::boolOrDefault($settings->credit_check_residential_auto_enabled ?? null, true),
                'auto_title' => self::textOrDefault($settings->credit_check_residential_auto_title ?? null, $defaults['residential']['auto_title']),
                'auto_description' => self::textOrDefault($settings->credit_check_residential_auto_description ?? null, $defaults['residential']['auto_description']),
                'manual_enabled' => self::boolOrDefault($settings->credit_check_residential_manual_enabled ?? null, true),
                'manual_title' => self::textOrDefault($settings->credit_check_residential_manual_title ?? null, $defaults['residential']['manual_title']),
                'manual_description' => self::textOrDefault($settings->credit_check_residential_manual_description ?? null, $defaults['residential']['manual_description']),
            ],
            'sme' => [
                'auto_enabled' => self::boolOrDefault($settings->credit_check_sme_auto_enabled ?? null, true),
                'auto_title' => self::textOrDefault($settings->credit_check_sme_auto_title ?? null, $defaults['sme']['auto_title']),
                'auto_description' => self::textOrDefault($settings->credit_check_sme_auto_description ?? null, $defaults['sme']['auto_description']),
                'manual_enabled' => self::boolOrDefault($settings->credit_check_sme_manual_enabled ?? null, true),
                'manual_title' => self::textOrDefault($settings->credit_check_sme_manual_title ?? null, $defaults['sme']['manual_title']),
                'manual_description' => self::textOrDefault($settings->credit_check_sme_manual_description ?? null, $defaults['sme']['manual_description']),
            ],
        ];
    }

    public static function forCustomerType(?string $customerType, ?BnplSettings $settings = null): array
    {
        $all = self::fromSettings($settings);
        $type = strtolower(trim((string) $customerType));
        $segment = in_array($type, ['sme', 'commercial'], true) ? 'sme' : 'residential';

        return [
            'intro' => $all['intro'],
            'continue_label' => $all['continue_label'],
            'unavailable_label' => $all['unavailable_label'],
            'segment' => $segment,
            ...$all[$segment],
        ];
    }

    private static function textOrDefault(?string $value, string $default): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : $default;
    }

    private static function boolOrDefault(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
