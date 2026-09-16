<?php

namespace App\Support;

use App\Models\BnplSettings;

class BnplFinancingPathCopy
{
    public static function defaults(): array
    {
        return [
            'title' => 'Financing Path',
            'intro' => 'Choose how you want to finance this order before completing the Final Application. Partner financiers return a decision in 24–72 hours after credit-check payment; Troosolar continues the full BNPL process flow.',
            'back_label' => 'Back',
            'troosolar_title' => 'Troosolar',
            'troosolar_description' => "Continue with Troosolar's BNPL process (credit check, approval, guarantor flow).",
            'partner_title' => 'Partner Financing',
            'partner_description' => "Partner financier — we'll get back to you within 24–72 hours after credit-check payment.",
            'continue_troosolar_label' => 'Continue to Full Loan Plan',
            'continue_partner_label' => 'Continue to Final Application',
        ];
    }

    public static function fromSettings(?BnplSettings $settings = null): array
    {
        $defaults = self::defaults();
        $settings = $settings ?? BnplSettings::get();

        return [
            'title' => self::textOrDefault($settings->financing_path_title ?? null, $defaults['title']),
            'intro' => self::textOrDefault($settings->financing_path_intro ?? null, $defaults['intro']),
            'back_label' => self::textOrDefault($settings->financing_path_back_label ?? null, $defaults['back_label']),
            'troosolar_title' => self::textOrDefault($settings->financing_path_troosolar_title ?? null, $defaults['troosolar_title']),
            'troosolar_description' => self::textOrDefault($settings->financing_path_troosolar_description ?? null, $defaults['troosolar_description']),
            'partner_title' => self::textOrDefault($settings->financing_path_partner_title ?? null, $defaults['partner_title']),
            'partner_description' => self::textOrDefault($settings->financing_path_partner_description ?? null, $defaults['partner_description']),
            'continue_troosolar_label' => self::textOrDefault($settings->financing_path_continue_troosolar_label ?? null, $defaults['continue_troosolar_label']),
            'continue_partner_label' => self::textOrDefault($settings->financing_path_continue_partner_label ?? null, $defaults['continue_partner_label']),
        ];
    }

    private static function textOrDefault(?string $value, string $default): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : $default;
    }
}
