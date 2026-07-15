<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'customer_type')) {
                $table->string('customer_type', 32)->nullable()->after('order_type')
                    ->comment('residential, sme, commercial');
            }
            if (! Schema::hasColumn('orders', 'installer_choice')) {
                $table->string('installer_choice', 32)->nullable()->after('customer_type')
                    ->comment('troosolar, own');
            }
            if (! Schema::hasColumn('orders', 'property_floors')) {
                $table->unsignedInteger('property_floors')->nullable()->after('installer_choice');
            }
            if (! Schema::hasColumn('orders', 'property_rooms')) {
                $table->unsignedInteger('property_rooms')->nullable()->after('property_floors');
            }
            if (! Schema::hasColumn('orders', 'is_gated_estate')) {
                $table->boolean('is_gated_estate')->nullable()->after('property_rooms');
            }
            if (! Schema::hasColumn('orders', 'estate_name')) {
                $table->string('estate_name')->nullable()->after('is_gated_estate');
            }
            if (! Schema::hasColumn('orders', 'estate_address')) {
                $table->text('estate_address')->nullable()->after('estate_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $cols = [
                'customer_type',
                'installer_choice',
                'property_floors',
                'property_rooms',
                'is_gated_estate',
                'estate_name',
                'estate_address',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
