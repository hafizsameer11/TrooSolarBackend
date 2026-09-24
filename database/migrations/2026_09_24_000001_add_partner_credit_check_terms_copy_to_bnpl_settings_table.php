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
                'credit_check_partner_terms_label' => 'text',
                'credit_check_partner_fee_note' => 'text',
            ];

            $after = Schema::hasColumn('bnpl_settings', 'credit_check_partner_routed_note')
                ? 'credit_check_partner_routed_note'
                : null;

            foreach ($cols as $name => $type) {
                if (Schema::hasColumn('bnpl_settings', $name)) {
                    continue;
                }
                $col = $table->text($name)->nullable();
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
                'credit_check_partner_terms_label',
                'credit_check_partner_fee_note',
            ] as $col) {
                if (Schema::hasColumn('bnpl_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
