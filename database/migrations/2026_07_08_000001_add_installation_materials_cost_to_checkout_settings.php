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
            if (! Schema::hasColumn('checkout_settings', 'installation_materials_cost')) {
                $table->unsignedInteger('installation_materials_cost')->default(0)->after('installation_flat_addon');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('checkout_settings')) {
            return;
        }

        Schema::table('checkout_settings', function (Blueprint $table) {
            if (Schema::hasColumn('checkout_settings', 'installation_materials_cost')) {
                $table->dropColumn('installation_materials_cost');
            }
        });
    }
};
