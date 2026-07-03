<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_requests', 'preferred_audit_date')) {
                $table->date('preferred_audit_date')->nullable()->after('estate_address');
            }
            if (! Schema::hasColumn('audit_requests', 'preferred_audit_time')) {
                $table->string('preferred_audit_time', 10)->nullable()->after('preferred_audit_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            if (Schema::hasColumn('audit_requests', 'preferred_audit_time')) {
                $table->dropColumn('preferred_audit_time');
            }
            if (Schema::hasColumn('audit_requests', 'preferred_audit_date')) {
                $table->dropColumn('preferred_audit_date');
            }
        });
    }
};
