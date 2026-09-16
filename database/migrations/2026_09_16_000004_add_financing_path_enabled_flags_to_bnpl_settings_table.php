<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bnpl_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('bnpl_settings', 'financing_path_troosolar_enabled')) {
                $table->boolean('financing_path_troosolar_enabled')->default(true)->after('financing_path_continue_partner_label');
            }
            if (! Schema::hasColumn('bnpl_settings', 'financing_path_partner_enabled')) {
                $table->boolean('financing_path_partner_enabled')->default(true)->after('financing_path_troosolar_enabled');
            }
            if (! Schema::hasColumn('bnpl_settings', 'financing_path_unavailable_label')) {
                $table->string('financing_path_unavailable_label')->nullable()->after('financing_path_partner_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bnpl_settings', function (Blueprint $table) {
            foreach ([
                'financing_path_troosolar_enabled',
                'financing_path_partner_enabled',
                'financing_path_unavailable_label',
            ] as $col) {
                if (Schema::hasColumn('bnpl_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
