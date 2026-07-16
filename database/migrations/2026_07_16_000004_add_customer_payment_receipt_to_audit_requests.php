<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_requests', 'customer_payment_receipt_path')) {
                $table->string('customer_payment_receipt_path')->nullable()->after('customer_payment_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            if (Schema::hasColumn('audit_requests', 'customer_payment_receipt_path')) {
                $table->dropColumn('customer_payment_receipt_path');
            }
        });
    }
};
