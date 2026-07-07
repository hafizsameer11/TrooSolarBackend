<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separate bundle order list + invoice fee rows per checkout flow (buy_now vs bnpl).
     */
    public function up(): void
    {
        Schema::table('custom_services', function (Blueprint $table) {
            if (! Schema::hasColumn('custom_services', 'flow_type')) {
                $table->string('flow_type', 16)->default('buy_now')->after('bundle_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('custom_services', function (Blueprint $table) {
            if (Schema::hasColumn('custom_services', 'flow_type')) {
                $table->dropColumn('flow_type');
            }
        });
    }
};
