<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove duplicate custom_services rows created before flow_type was saved correctly from Admin.
     */
    public function up(): void
    {
        if (! Schema::hasTable('custom_services')) {
            return;
        }

        $hasFlowType = Schema::hasColumn('custom_services', 'flow_type');

        $rows = DB::table('custom_services')->orderBy('id')->get();

        $seen = [];
        $deleteIds = [];

        foreach ($rows as $row) {
            $flow = $hasFlowType ? ($row->flow_type ?? 'buy_now') : 'buy_now';
            $key = implode('|', [
                (string) $row->bundle_id,
                $flow,
                (string) ($row->title ?? ''),
                (string) ($row->service_amount ?? 0),
            ]);

            if (isset($seen[$key])) {
                $deleteIds[] = $row->id;
            } else {
                $seen[$key] = $row->id;
            }
        }

        if (count($deleteIds) > 0) {
            foreach (array_chunk($deleteIds, 500) as $chunk) {
                DB::table('custom_services')->whereIn('id', $chunk)->delete();
            }
        }
    }

    public function down(): void
    {
        // Not reversible.
    }
};
