<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            if (! Schema::hasColumn('bundles', 'bnpl_price')) {
                $table->decimal('bnpl_price', 12, 2)->nullable()->after('discount_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            if (Schema::hasColumn('bundles', 'bnpl_price')) {
                $table->dropColumn('bnpl_price');
            }
        });
    }
};
