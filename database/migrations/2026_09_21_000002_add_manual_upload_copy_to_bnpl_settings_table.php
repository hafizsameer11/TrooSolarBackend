<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bnpl_settings', function (Blueprint $table) {
            $cols = [
                'credit_check_residential_manual_upload_intro' => 'text',
                'credit_check_residential_manual_docs_title' => 'string',
                'credit_check_residential_manual_bank_label' => 'string',
                'credit_check_residential_manual_bank_hint' => 'string',
                'credit_check_residential_manual_selfie_label' => 'string',
                'credit_check_residential_manual_selfie_button' => 'string',
                'credit_check_residential_manual_selfie_hint' => 'string',
                'credit_check_residential_manual_submit_label' => 'string',
                'credit_check_sme_manual_upload_intro' => 'text',
                'credit_check_sme_manual_docs_title' => 'string',
                'credit_check_sme_manual_bank_label' => 'string',
                'credit_check_sme_manual_bank_hint' => 'string',
                'credit_check_sme_manual_selfie_label' => 'string',
                'credit_check_sme_manual_selfie_button' => 'string',
                'credit_check_sme_manual_selfie_hint' => 'string',
                'credit_check_sme_manual_submit_label' => 'string',
            ];

            $after = Schema::hasColumn('bnpl_settings', 'credit_check_partner_routed_note')
                ? 'credit_check_partner_routed_note'
                : null;

            foreach ($cols as $name => $type) {
                if (Schema::hasColumn('bnpl_settings', $name)) {
                    continue;
                }
                $col = $type === 'text'
                    ? $table->text($name)->nullable()
                    : $table->string($name)->nullable();
                if ($after) {
                    $col->after($after);
                }
                $after = $name;
            }
        });
    }

    public function down(): void
    {
        Schema::table('bnpl_settings', function (Blueprint $table) {
            foreach ([
                'credit_check_residential_manual_upload_intro',
                'credit_check_residential_manual_docs_title',
                'credit_check_residential_manual_bank_label',
                'credit_check_residential_manual_bank_hint',
                'credit_check_residential_manual_selfie_label',
                'credit_check_residential_manual_selfie_button',
                'credit_check_residential_manual_selfie_hint',
                'credit_check_residential_manual_submit_label',
                'credit_check_sme_manual_upload_intro',
                'credit_check_sme_manual_docs_title',
                'credit_check_sme_manual_bank_label',
                'credit_check_sme_manual_bank_hint',
                'credit_check_sme_manual_selfie_label',
                'credit_check_sme_manual_selfie_button',
                'credit_check_sme_manual_selfie_hint',
                'credit_check_sme_manual_submit_label',
            ] as $col) {
                if (Schema::hasColumn('bnpl_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
