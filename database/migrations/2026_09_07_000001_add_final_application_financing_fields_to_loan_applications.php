<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('loan_applications', 'financing_path')) {
                $table->string('financing_path', 32)->nullable()->after('customer_type');
            }
            if (! Schema::hasColumn('loan_applications', 'financing_partner_id')) {
                $table->unsignedBigInteger('financing_partner_id')->nullable()->after('financing_path');
            }
            if (! Schema::hasColumn('loan_applications', 'finance_agreement_accepted_at')) {
                $table->timestamp('finance_agreement_accepted_at')->nullable()->after('financing_partner_id');
            }
            if (! Schema::hasColumn('loan_applications', 'property_status')) {
                $table->string('property_status', 32)->nullable()->after('property_rooms');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            foreach (['financing_path', 'financing_partner_id', 'finance_agreement_accepted_at', 'property_status'] as $col) {
                if (Schema::hasColumn('loan_applications', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
