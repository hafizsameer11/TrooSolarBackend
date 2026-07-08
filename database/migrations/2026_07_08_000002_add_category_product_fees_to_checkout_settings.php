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
            if (! Schema::hasColumn('checkout_settings', 'category_installation_fees')) {
                $table->json('category_installation_fees')->nullable()->after('category_delivery_fees');
            }
            if (! Schema::hasColumn('checkout_settings', 'category_materials_fees')) {
                $table->json('category_materials_fees')->nullable()->after('category_installation_fees');
            }
            if (! Schema::hasColumn('checkout_settings', 'category_inspection_fees')) {
                $table->json('category_inspection_fees')->nullable()->after('category_materials_fees');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('checkout_settings')) {
            return;
        }

        Schema::table('checkout_settings', function (Blueprint $table) {
            foreach (['category_installation_fees', 'category_materials_fees', 'category_inspection_fees'] as $col) {
                if (Schema::hasColumn('checkout_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
