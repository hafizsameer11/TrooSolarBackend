<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('loan_applications', 'partner_offer_writeup')) {
                $table->text('partner_offer_writeup')
                    ->nullable()
                    ->after('partner_offer_documents');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            if (Schema::hasColumn('loan_applications', 'partner_offer_writeup')) {
                $table->dropColumn('partner_offer_writeup');
            }
        });
    }
};
