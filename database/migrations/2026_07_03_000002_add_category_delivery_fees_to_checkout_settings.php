<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('checkout_settings', 'category_delivery_fees')) {
                $table->json('category_delivery_fees')->nullable()->after('delivery_fee');
            }
        });
    }

    public function down(): void
    {
        Schema::table('checkout_settings', function (Blueprint $table) {
            if (Schema::hasColumn('checkout_settings', 'category_delivery_fees')) {
                $table->dropColumn('category_delivery_fees');
            }
        });
    }
};
