<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('loan_applications', 'partner_offer_interest_rate')) {
                $table->decimal('partner_offer_interest_rate', 8, 4)->nullable()->after('counter_offer_min_tenor');
            }
            if (! Schema::hasColumn('loan_applications', 'partner_offer_initial_deposit')) {
                $table->decimal('partner_offer_initial_deposit', 14, 2)->nullable()->after('partner_offer_interest_rate');
            }
            if (! Schema::hasColumn('loan_applications', 'partner_offer_admin_fees')) {
                $table->decimal('partner_offer_admin_fees', 14, 2)->nullable()->after('partner_offer_initial_deposit');
            }
            if (! Schema::hasColumn('loan_applications', 'partner_offer_repayment_amount')) {
                $table->decimal('partner_offer_repayment_amount', 14, 2)->nullable()->after('partner_offer_admin_fees');
            }
            if (! Schema::hasColumn('loan_applications', 'partner_offer_loan_amount')) {
                $table->decimal('partner_offer_loan_amount', 14, 2)->nullable()->after('partner_offer_repayment_amount');
            }
            if (! Schema::hasColumn('loan_applications', 'partner_offer_tenor')) {
                $table->unsignedSmallInteger('partner_offer_tenor')->nullable()->after('partner_offer_loan_amount');
            }
            if (! Schema::hasColumn('loan_applications', 'partner_offer_documents')) {
                $table->json('partner_offer_documents')->nullable()->after('partner_offer_tenor');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $cols = [
                'partner_offer_interest_rate',
                'partner_offer_initial_deposit',
                'partner_offer_admin_fees',
                'partner_offer_repayment_amount',
                'partner_offer_loan_amount',
                'partner_offer_tenor',
                'partner_offer_documents',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('loan_applications', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
