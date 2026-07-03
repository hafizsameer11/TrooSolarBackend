<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_requests', 'approval_payment_date')) {
                $table->date('approval_payment_date')->nullable()->after('admin_notes');
            }
            if (! Schema::hasColumn('audit_requests', 'approval_payment_time')) {
                $table->string('approval_payment_time', 10)->nullable()->after('approval_payment_date');
            }
            if (! Schema::hasColumn('audit_requests', 'approval_payment_amount')) {
                $table->decimal('approval_payment_amount', 14, 2)->nullable()->after('approval_payment_time');
            }
            if (! Schema::hasColumn('audit_requests', 'approval_payment_account_details')) {
                $table->text('approval_payment_account_details')->nullable()->after('approval_payment_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            foreach ([
                'approval_payment_account_details',
                'approval_payment_amount',
                'approval_payment_time',
                'approval_payment_date',
            ] as $col) {
                if (Schema::hasColumn('audit_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
