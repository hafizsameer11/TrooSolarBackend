<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_requests', 'customer_has_paid')) {
                $table->boolean('customer_has_paid')->default(false)->after('approval_payment_account_details');
            }
            if (! Schema::hasColumn('audit_requests', 'customer_payment_date')) {
                $table->date('customer_payment_date')->nullable()->after('customer_has_paid');
            }
            if (! Schema::hasColumn('audit_requests', 'customer_payment_time')) {
                $table->string('customer_payment_time', 10)->nullable()->after('customer_payment_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            foreach (['customer_payment_time', 'customer_payment_date', 'customer_has_paid'] as $col) {
                if (Schema::hasColumn('audit_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
