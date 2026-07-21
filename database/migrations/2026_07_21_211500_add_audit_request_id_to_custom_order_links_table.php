<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_order_links', function (Blueprint $table) {
            if (! Schema::hasColumn('custom_order_links', 'audit_request_id')) {
                $table->foreignId('audit_request_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('audit_requests')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('custom_order_links', function (Blueprint $table) {
            if (Schema::hasColumn('custom_order_links', 'audit_request_id')) {
                $table->dropConstrainedForeignId('audit_request_id');
            }
        });
    }
};
