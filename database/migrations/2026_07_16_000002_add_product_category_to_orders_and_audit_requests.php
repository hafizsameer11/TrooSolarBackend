<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_requests', 'product_category')) {
                $table->string('product_category')->nullable()->after('customer_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            if (Schema::hasColumn('audit_requests', 'product_category')) {
                $table->dropColumn('product_category');
            }
        });
    }
};
