<?php

namespace App\Support;

use App\Models\BnplSettings;

class BnplTermsGate
{
    public static function defaults(): array
    {
        return [
            'title' => 'Terms of Use Agreement',
            'subtitle' => 'Accept the terms of service and privacy policy to continue',
            'checkbox_prefix' => 'I accept the',
            'terms_label' => 'Terms Of Service',
            'privacy_label' => 'Privacy Policy',
            'proceed_label' => 'Proceed',
            'terms_url' => 'https://troosolar.io/terms-of-service/',
            'privacy_url' => 'https://troosolar.io/privacy-policy/',
        ];
    }

    public static function fromSettings(?BnplSettings $settings = null): array
    {
        $defaults = self::defaults();
        $settings = $settings ?? BnplSettings::get();

        return [
            'title' => self::textOrDefault($settings->terms_gate_title ?? null, $defaults['title']),
            'subtitle' => self::textOrDefault($settings->terms_gate_subtitle ?? null, $defaults['subtitle']),
            'checkbox_prefix' => self::textOrDefault($settings->terms_gate_checkbox_prefix ?? null, $defaults['checkbox_prefix']),
            'terms_label' => self::textOrDefault($settings->terms_gate_terms_label ?? null, $defaults['terms_label']),
            'privacy_label' => self::textOrDefault($settings->terms_gate_privacy_label ?? null, $defaults['privacy_label']),
            'proceed_label' => self::textOrDefault($settings->terms_gate_proceed_label ?? null, $defaults['proceed_label']),
            'terms_url' => self::textOrDefault($settings->terms_of_service_url ?? null, $defaults['terms_url']),
            'privacy_url' => self::textOrDefault($settings->terms_privacy_policy_url ?? null, $defaults['privacy_url']),
        ];
    }

    private static function textOrDefault(?string $value, string $default): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : $default;
    }
}
