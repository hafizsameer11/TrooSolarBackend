<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (! Schema::hasColumn('brands', 'sort_order')) {
                $table->unsignedInteger('sort_order')->nullable()->after('category_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            if (Schema::hasColumn('brands', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });
    }
};
