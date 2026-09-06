<?php

use App\Models\Partner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (! Schema::hasColumn('partners', 'slug')) {
                $table->string('slug', 64)->nullable()->unique()->after('name');
            }
        });

        // Ensure Troosolar appears in Settings → Financing Partner and can be Active/Inactive.
        $existing = Partner::query()
            ->where(function ($q) {
                $q->where('slug', Partner::SLUG_TROOSOLAR)
                    ->orWhereRaw('LOWER(name) = ?', ['troosolar']);
            })
            ->first();

        if ($existing) {
            $existing->slug = Partner::SLUG_TROOSOLAR;
            if ($existing->status === null || $existing->status === '') {
                $existing->status = 'Active';
            }
            $existing->save();
        } else {
            Partner::create([
                'name' => 'Troosolar',
                'slug' => Partner::SLUG_TROOSOLAR,
                'email' => 'bnpl@troosolar.com',
                'status' => 'Active',
                'no_of_loans' => 0,
                'amount' => 0,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (Schema::hasColumn('partners', 'slug')) {
                $table->dropColumn('slug');
            }
        });
    }
};
