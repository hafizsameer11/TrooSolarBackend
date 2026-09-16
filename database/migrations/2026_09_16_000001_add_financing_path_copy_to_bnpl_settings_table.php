<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bnpl_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('bnpl_settings', 'financing_path_intro')) {
                $table->text('financing_path_intro')->nullable()->after('terms_privacy_policy_url');
            }
            if (! Schema::hasColumn('bnpl_settings', 'financing_path_troosolar_title')) {
                $table->string('financing_path_troosolar_title')->nullable()->after('financing_path_intro');
            }
            if (! Schema::hasColumn('bnpl_settings', 'financing_path_troosolar_description')) {
                $table->text('financing_path_troosolar_description')->nullable()->after('financing_path_troosolar_title');
            }
            if (! Schema::hasColumn('bnpl_settings', 'financing_path_partner_title')) {
                $table->string('financing_path_partner_title')->nullable()->after('financing_path_troosolar_description');
            }
            if (! Schema::hasColumn('bnpl_settings', 'financing_path_partner_description')) {
                $table->text('financing_path_partner_description')->nullable()->after('financing_path_partner_title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bnpl_settings', function (Blueprint $table) {
            foreach ([
                'financing_path_intro',
                'financing_path_troosolar_title',
                'financing_path_troosolar_description',
                'financing_path_partner_title',
                'financing_path_partner_description',
            ] as $col) {
                if (Schema::hasColumn('bnpl_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
