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
                'credit_check_intro' => 'text',
                'credit_check_continue_label' => 'string',
                'credit_check_unavailable_label' => 'string',
                'credit_check_residential_auto_enabled' => 'boolean',
                'credit_check_residential_auto_title' => 'string',
                'credit_check_residential_auto_description' => 'text',
                'credit_check_residential_manual_enabled' => 'boolean',
                'credit_check_residential_manual_title' => 'string',
                'credit_check_residential_manual_description' => 'text',
                'credit_check_sme_auto_enabled' => 'boolean',
                'credit_check_sme_auto_title' => 'string',
                'credit_check_sme_auto_description' => 'text',
                'credit_check_sme_manual_enabled' => 'boolean',
                'credit_check_sme_manual_title' => 'string',
                'credit_check_sme_manual_description' => 'text',
            ];

            $after = Schema::hasColumn('bnpl_settings', 'finance_agreement_sme_text')
                ? 'finance_agreement_sme_text'
                : null;

            foreach ($cols as $name => $type) {
                if (Schema::hasColumn('bnpl_settings', $name)) {
                    continue;
                }
                if ($type === 'boolean') {
                    $col = $table->boolean($name)->default(true);
                } elseif ($type === 'text') {
                    $col = $table->text($name)->nullable();
                } else {
                    $col = $table->string($name)->nullable();
                }
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
                'credit_check_intro',
                'credit_check_continue_label',
                'credit_check_unavailable_label',
                'credit_check_residential_auto_enabled',
                'credit_check_residential_auto_title',
                'credit_check_residential_auto_description',
                'credit_check_residential_manual_enabled',
                'credit_check_residential_manual_title',
                'credit_check_residential_manual_description',
                'credit_check_sme_auto_enabled',
                'credit_check_sme_auto_title',
                'credit_check_sme_auto_description',
                'credit_check_sme_manual_enabled',
                'credit_check_sme_manual_title',
                'credit_check_sme_manual_description',
            ] as $col) {
                if (Schema::hasColumn('bnpl_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
