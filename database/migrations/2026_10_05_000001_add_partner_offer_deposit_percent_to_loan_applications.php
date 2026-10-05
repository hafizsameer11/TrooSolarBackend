<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('loan_applications', 'partner_offer_deposit_percent')) {
                $table->decimal('partner_offer_deposit_percent', 8, 2)
                    ->nullable()
                    ->after('partner_offer_interest_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            if (Schema::hasColumn('loan_applications', 'partner_offer_deposit_percent')) {
                $table->dropColumn('partner_offer_deposit_percent');
            }
        });
    }
};
