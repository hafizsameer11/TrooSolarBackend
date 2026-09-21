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
                'credit_check_partner_fee_title' => 'string',
                'credit_check_partner_fee_intro' => 'text',
                'credit_check_partner_success_message' => 'text',
                'credit_check_partner_routed_note' => 'text',
            ];

            $after = Schema::hasColumn('bnpl_settings', 'credit_check_sme_manual_description')
                ? 'credit_check_sme_manual_description'
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
                'credit_check_partner_fee_title',
                'credit_check_partner_fee_intro',
                'credit_check_partner_success_message',
                'credit_check_partner_routed_note',
            ] as $col) {
                if (Schema::hasColumn('bnpl_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
