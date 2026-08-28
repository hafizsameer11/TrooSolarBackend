<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bnpl_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('bnpl_settings', 'terms_gate_title')) {
                $table->string('terms_gate_title')->nullable()->after('loan_durations');
            }
            if (! Schema::hasColumn('bnpl_settings', 'terms_gate_subtitle')) {
                $table->text('terms_gate_subtitle')->nullable()->after('terms_gate_title');
            }
            if (! Schema::hasColumn('bnpl_settings', 'terms_gate_checkbox_prefix')) {
                $table->string('terms_gate_checkbox_prefix')->nullable()->after('terms_gate_subtitle');
            }
            if (! Schema::hasColumn('bnpl_settings', 'terms_gate_terms_label')) {
                $table->string('terms_gate_terms_label')->nullable()->after('terms_gate_checkbox_prefix');
            }
            if (! Schema::hasColumn('bnpl_settings', 'terms_gate_privacy_label')) {
                $table->string('terms_gate_privacy_label')->nullable()->after('terms_gate_terms_label');
            }
            if (! Schema::hasColumn('bnpl_settings', 'terms_gate_proceed_label')) {
                $table->string('terms_gate_proceed_label')->nullable()->after('terms_gate_privacy_label');
            }
            if (! Schema::hasColumn('bnpl_settings', 'terms_of_service_url')) {
                $table->string('terms_of_service_url')->nullable()->after('terms_gate_proceed_label');
            }
            if (! Schema::hasColumn('bnpl_settings', 'terms_privacy_policy_url')) {
                $table->string('terms_privacy_policy_url')->nullable()->after('terms_of_service_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bnpl_settings', function (Blueprint $table) {
            foreach ([
                'terms_gate_title',
                'terms_gate_subtitle',
                'terms_gate_checkbox_prefix',
                'terms_gate_terms_label',
                'terms_gate_privacy_label',
                'terms_gate_proceed_label',
                'terms_of_service_url',
                'terms_privacy_policy_url',
            ] as $col) {
                if (Schema::hasColumn('bnpl_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
