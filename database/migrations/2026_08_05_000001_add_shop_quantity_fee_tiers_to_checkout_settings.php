<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('checkout_settings')) {
            return;
        }

        Schema::table('checkout_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('checkout_settings', 'shop_quantity_fee_tiers')) {
                $table->json('shop_quantity_fee_tiers')->nullable()->after('category_inspection_fees');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('checkout_settings')) {
            return;
        }

        Schema::table('checkout_settings', function (Blueprint $table) {
            if (Schema::hasColumn('checkout_settings', 'shop_quantity_fee_tiers')) {
                $table->dropColumn('shop_quantity_fee_tiers');
            }
        });
    }
};
