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
            if (! Schema::hasColumn('checkout_settings', 'own_installer_include_inspection')) {
                $table->boolean('own_installer_include_inspection')->default(false)->after('category_inspection_fees');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('checkout_settings')) {
            return;
        }

        Schema::table('checkout_settings', function (Blueprint $table) {
            if (Schema::hasColumn('checkout_settings', 'own_installer_include_inspection')) {
                $table->dropColumn('own_installer_include_inspection');
            }
        });
    }
};
