<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bnpl_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('bnpl_settings', 'financing_path_title')) {
                $table->string('financing_path_title')->nullable()->after('financing_path_partner_description');
            }
            if (! Schema::hasColumn('bnpl_settings', 'financing_path_back_label')) {
                $table->string('financing_path_back_label')->nullable()->after('financing_path_title');
            }
            if (! Schema::hasColumn('bnpl_settings', 'financing_path_continue_troosolar_label')) {
                $table->string('financing_path_continue_troosolar_label')->nullable()->after('financing_path_back_label');
            }
            if (! Schema::hasColumn('bnpl_settings', 'financing_path_continue_partner_label')) {
                $table->string('financing_path_continue_partner_label')->nullable()->after('financing_path_continue_troosolar_label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bnpl_settings', function (Blueprint $table) {
            foreach ([
                'financing_path_title',
                'financing_path_back_label',
                'financing_path_continue_troosolar_label',
                'financing_path_continue_partner_label',
            ] as $col) {
                if (Schema::hasColumn('bnpl_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
