<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bnpl_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('bnpl_settings', 'finance_agreement_modal_title')) {
                $table->string('finance_agreement_modal_title')->nullable()->after('financing_path_continue_partner_label');
            }
            if (! Schema::hasColumn('bnpl_settings', 'finance_agreement_checkbox_prefix')) {
                $table->string('finance_agreement_checkbox_prefix')->nullable()->after('finance_agreement_modal_title');
            }
            if (! Schema::hasColumn('bnpl_settings', 'finance_agreement_link_label')) {
                $table->string('finance_agreement_link_label')->nullable()->after('finance_agreement_checkbox_prefix');
            }
            if (! Schema::hasColumn('bnpl_settings', 'finance_agreement_close_label')) {
                $table->string('finance_agreement_close_label')->nullable()->after('finance_agreement_link_label');
            }
            if (! Schema::hasColumn('bnpl_settings', 'finance_agreement_accept_label')) {
                $table->string('finance_agreement_accept_label')->nullable()->after('finance_agreement_close_label');
            }
            if (! Schema::hasColumn('bnpl_settings', 'finance_agreement_residential_text')) {
                $table->longText('finance_agreement_residential_text')->nullable()->after('finance_agreement_accept_label');
            }
            if (! Schema::hasColumn('bnpl_settings', 'finance_agreement_sme_text')) {
                $table->longText('finance_agreement_sme_text')->nullable()->after('finance_agreement_residential_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bnpl_settings', function (Blueprint $table) {
            foreach ([
                'finance_agreement_modal_title',
                'finance_agreement_checkbox_prefix',
                'finance_agreement_link_label',
                'finance_agreement_close_label',
                'finance_agreement_accept_label',
                'finance_agreement_residential_text',
                'finance_agreement_sme_text',
            ] as $col) {
                if (Schema::hasColumn('bnpl_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
