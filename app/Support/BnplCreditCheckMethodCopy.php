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
                'manual_upload_intro' => 'Upload your documents for manual credit review.',
                'manual_docs_title' => 'Required Documents',
                'manual_bank_label' => 'Bank Statement (Last 6 Months)',
                'manual_bank_hint' => 'Accepted formats: PDF, JPG, PNG (Max 10MB)',
                'manual_selfie_label' => 'Live Photo / Selfie',
                'manual_selfie_button' => 'Tap to Open Camera & Take Selfie',
                'manual_selfie_hint' => 'A live selfie is required for identity verification.',
                'manual_submit_label' => 'Submit for Manual Review',
            ],
            'sme' => [
                'auto_enabled' => true,
                'auto_title' => 'Connect your bank (Recommended)',
                'auto_description' => 'Link your business account with Mono, pay the verification fee, then we run the credit check automatically.',
                'manual_enabled' => true,
                'manual_title' => 'Manual review',
                'manual_description' => 'Pay the verification fee first, then upload your bank statement and selfie.',
                'manual_upload_intro' => 'Upload your business documents for manual credit review.',
                'manual_docs_title' => 'Required Documents',
                'manual_bank_label' => 'Business Bank Statement (Last 6 Months)',
                'manual_bank_hint' => 'Accepted formats: PDF, JPG, PNG (Max 10MB)',
                'manual_selfie_label' => 'Live Photo / Selfie',
                'manual_selfie_button' => 'Tap to Open Camera & Take Selfie',
                'manual_selfie_hint' => 'A live selfie is required for identity verification.',
                'manual_submit_label' => 'Submit for Manual Review',
            ],
            'partner' => [
                'fee_title' => 'Credit Check Fee',
                'fee_intro' => "Pay the credit check fee to send your application to your selected financing partner. We'll get back to you within 2 - 5 working days.",
                'success_message' => 'We have received your application for partner financing. We will get back to you within 2 - 5 working days.',
                'routed_note' => "Your application was routed to a financing partner. Troosolar's internal guarantor flow does not continue for this path.",
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
            'residential' => self::segmentFromSettings($settings, 'residential', $defaults['residential']),
            'sme' => self::segmentFromSettings($settings, 'sme', $defaults['sme']),
            'partner' => [
                'fee_title' => self::textOrDefault($settings->credit_check_partner_fee_title ?? null, $defaults['partner']['fee_title']),
                'fee_intro' => self::textOrDefault($settings->credit_check_partner_fee_intro ?? null, $defaults['partner']['fee_intro']),
                'success_message' => self::textOrDefault($settings->credit_check_partner_success_message ?? null, $defaults['partner']['success_message']),
                'routed_note' => self::textOrDefault($settings->credit_check_partner_routed_note ?? null, $defaults['partner']['routed_note']),
            ],
        ];
    }

    private static function segmentFromSettings(BnplSettings $settings, string $segment, array $defaults): array
    {
        $prefix = $segment === 'sme' ? 'credit_check_sme_' : 'credit_check_residential_';

        return [
            'auto_enabled' => self::boolOrDefault($settings->{"{$prefix}auto_enabled"} ?? null, true),
            'auto_title' => self::textOrDefault($settings->{"{$prefix}auto_title"} ?? null, $defaults['auto_title']),
            'auto_description' => self::textOrDefault($settings->{"{$prefix}auto_description"} ?? null, $defaults['auto_description']),
            'manual_enabled' => self::boolOrDefault($settings->{"{$prefix}manual_enabled"} ?? null, true),
            'manual_title' => self::textOrDefault($settings->{"{$prefix}manual_title"} ?? null, $defaults['manual_title']),
            'manual_description' => self::textOrDefault($settings->{"{$prefix}manual_description"} ?? null, $defaults['manual_description']),
            'manual_upload_intro' => self::textOrDefault($settings->{"{$prefix}manual_upload_intro"} ?? null, $defaults['manual_upload_intro']),
            'manual_docs_title' => self::textOrDefault($settings->{"{$prefix}manual_docs_title"} ?? null, $defaults['manual_docs_title']),
            'manual_bank_label' => self::textOrDefault($settings->{"{$prefix}manual_bank_label"} ?? null, $defaults['manual_bank_label']),
            'manual_bank_hint' => self::textOrDefault($settings->{"{$prefix}manual_bank_hint"} ?? null, $defaults['manual_bank_hint']),
            'manual_selfie_label' => self::textOrDefault($settings->{"{$prefix}manual_selfie_label"} ?? null, $defaults['manual_selfie_label']),
            'manual_selfie_button' => self::textOrDefault($settings->{"{$prefix}manual_selfie_button"} ?? null, $defaults['manual_selfie_button']),
            'manual_selfie_hint' => self::textOrDefault($settings->{"{$prefix}manual_selfie_hint"} ?? null, $defaults['manual_selfie_hint']),
            'manual_submit_label' => self::textOrDefault($settings->{"{$prefix}manual_submit_label"} ?? null, $defaults['manual_submit_label']),
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
