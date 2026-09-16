<?php

namespace App\Support;

use App\Models\BnplSettings;

class BnplFinancingPathCopy
{
    public static function defaults(): array
    {
        return [
            'intro' => 'Choose how you want to finance this order before completing the Final Application. Partner financiers return a decision in 24–72 hours after credit-check payment; Troosolar continues the full BNPL process flow.',
            'troosolar_title' => 'Troosolar',
            'troosolar_description' => "Continue with Troosolar's BNPL process (credit check, approval, guarantor flow).",
            'partner_title' => 'Partner Financing',
            'partner_description' => "Partner financier — we'll get back to you within 24–72 hours after credit-check payment.",
        ];
    }

    public static function fromSettings(?BnplSettings $settings = null): array
    {
        $defaults = self::defaults();
        $settings = $settings ?? BnplSettings::get();

        return [
            'intro' => self::textOrDefault($settings->financing_path_intro ?? null, $defaults['intro']),
            'troosolar_title' => self::textOrDefault($settings->financing_path_troosolar_title ?? null, $defaults['troosolar_title']),
            'troosolar_description' => self::textOrDefault($settings->financing_path_troosolar_description ?? null, $defaults['troosolar_description']),
            'partner_title' => self::textOrDefault($settings->financing_path_partner_title ?? null, $defaults['partner_title']),
            'partner_description' => self::textOrDefault($settings->financing_path_partner_description ?? null, $defaults['partner_description']),
        ];
    }

    private static function textOrDefault(?string $value, string $default): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : $default;
    }
}
