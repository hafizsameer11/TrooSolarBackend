<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            if (! Schema::hasColumn('loan_applications', 'installation_admin_proposed_date')) {
                $table->date('installation_admin_proposed_date')->nullable()->after('installation_rejected_dates');
            }
            if (! Schema::hasColumn('loan_applications', 'installation_rejection_note')) {
                $table->text('installation_rejection_note')->nullable()->after('installation_admin_proposed_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('loan_applications', 'installation_admin_proposed_date')) {
                $cols[] = 'installation_admin_proposed_date';
            }
            if (Schema::hasColumn('loan_applications', 'installation_rejection_note')) {
                $cols[] = 'installation_rejection_note';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
