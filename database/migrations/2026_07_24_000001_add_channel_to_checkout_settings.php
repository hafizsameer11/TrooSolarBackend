<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const CHANNEL_BUY_NOW = 'buy_now';

    public const CHANNEL_SHOP = 'shop';

    public function up(): void
    {
        if (! Schema::hasTable('checkout_settings')) {
            return;
        }

        if (! Schema::hasColumn('checkout_settings', 'channel')) {
            Schema::table('checkout_settings', function (Blueprint $table) {
                $table->string('channel', 32)
                    ->default(self::CHANNEL_BUY_NOW)
                    ->after('id');
            });
        }

        // Existing singleton row becomes Buy Now / BNPL settings.
        $firstId = DB::table('checkout_settings')->orderBy('id')->value('id');
        if ($firstId) {
            DB::table('checkout_settings')
                ->where('id', $firstId)
                ->update(['channel' => self::CHANNEL_BUY_NOW]);
        }

        DB::table('checkout_settings')
            ->where('id', '!=', $firstId)
            ->where('channel', '!=', self::CHANNEL_SHOP)
            ->update(['channel' => self::CHANNEL_BUY_NOW]);

        $buyNow = DB::table('checkout_settings')
            ->where('channel', self::CHANNEL_BUY_NOW)
            ->orderBy('id')
            ->first();

        $shopExists = DB::table('checkout_settings')
            ->where('channel', self::CHANNEL_SHOP)
            ->exists();

        if (! $shopExists && $buyNow) {
            $copy = (array) $buyNow;
            unset($copy['id'], $copy['created_at'], $copy['updated_at']);
            $copy['channel'] = self::CHANNEL_SHOP;
            $copy['created_at'] = now();
            $copy['updated_at'] = now();
            // Decode JSON columns if driver returned strings.
            foreach ([
                'category_delivery_fees',
                'category_installation_fees',
                'category_materials_fees',
                'category_inspection_fees',
            ] as $jsonCol) {
                if (! array_key_exists($jsonCol, $copy)) {
                    continue;
                }
                if (is_string($copy[$jsonCol])) {
                    $decoded = json_decode($copy[$jsonCol], true);
                    $copy[$jsonCol] = json_encode(is_array($decoded) ? $decoded : []);
                } elseif (is_array($copy[$jsonCol])) {
                    $copy[$jsonCol] = json_encode($copy[$jsonCol]);
                }
            }
            DB::table('checkout_settings')->insert($copy);
        }

        // Deduplicate buy_now rows keeping the lowest id.
        $buyNowIds = DB::table('checkout_settings')
            ->where('channel', self::CHANNEL_BUY_NOW)
            ->orderBy('id')
            ->pluck('id');
        if ($buyNowIds->count() > 1) {
            DB::table('checkout_settings')
                ->whereIn('id', $buyNowIds->slice(1)->all())
                ->delete();
        }

        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->unique('channel', 'checkout_settings_channel_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('checkout_settings')) {
            return;
        }

        Schema::table('checkout_settings', function (Blueprint $table) {
            $table->dropUnique('checkout_settings_channel_unique');
        });

        DB::table('checkout_settings')->where('channel', self::CHANNEL_SHOP)->delete();

        if (Schema::hasColumn('checkout_settings', 'channel')) {
            Schema::table('checkout_settings', function (Blueprint $table) {
                $table->dropColumn('channel');
            });
        }
    }
};
