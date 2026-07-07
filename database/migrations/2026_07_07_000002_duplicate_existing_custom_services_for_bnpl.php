<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing bundle order lists were saved before flow_type existed (all buy_now).
     * Copy them to bnpl so both checkout flows keep the same configuration.
     */
    public function up(): void
    {
        if (! Schema::hasTable('custom_services') || ! Schema::hasColumn('custom_services', 'flow_type')) {
            return;
        }

        DB::table('custom_services')
            ->whereNull('flow_type')
            ->update(['flow_type' => 'buy_now']);

        $bundleIds = DB::table('custom_services')
            ->select('bundle_id')
            ->distinct()
            ->pluck('bundle_id');

        $now = now();

        foreach ($bundleIds as $bundleId) {
            $hasBnplRows = DB::table('custom_services')
                ->where('bundle_id', $bundleId)
                ->where('flow_type', 'bnpl')
                ->exists();

            if ($hasBnplRows) {
                continue;
            }

            $buyNowRows = DB::table('custom_services')
                ->where('bundle_id', $bundleId)
                ->where('flow_type', 'buy_now')
                ->orderBy('id')
                ->get();

            foreach ($buyNowRows as $row) {
                $insert = [
                    'bundle_id' => $row->bundle_id,
                    'flow_type' => 'bnpl',
                    'title' => $row->title,
                    'service_amount' => $row->service_amount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (Schema::hasColumn('custom_services', 'quantity')) {
                    $insert['quantity'] = $row->quantity ?? 1;
                    $insert['unit'] = $row->unit ?? 'Nos';
                    $insert['quantity_applies'] = $row->quantity_applies ?? true;
                }

                DB::table('custom_services')->insert($insert);
            }
        }
    }

    public function down(): void
    {
        // Data migration is not safely reversible without tracking inserted ids.
    }
};
