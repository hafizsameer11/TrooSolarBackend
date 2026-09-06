<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    use HasFactory;

    public const SLUG_TROOSOLAR = 'troosolar';

    protected $fillable = [
        'name',
        'slug',
        'email',
        'status',
        'no_of_loans',
        'amount',
    ];

    public function isTroosolar(): bool
    {
        return strtolower((string) $this->slug) === self::SLUG_TROOSOLAR;
    }

    public function isActive(): bool
    {
        return strtolower(trim((string) $this->status)) === 'active';
    }

    public static function normalizeStatus(?string $status): ?string
    {
        if ($status === null || trim($status) === '') {
            return null;
        }
        $lower = strtolower(trim($status));
        if (in_array($lower, ['active', '1', 'true', 'yes'], true)) {
            return 'Active';
        }
        if (in_array($lower, ['inactive', '0', 'false', 'no'], true)) {
            return 'Inactive';
        }

        return $status;
    }

    public static function ensureTroosolarPartner(): self
    {
        $partner = static::query()
            ->where(function ($q) {
                $q->where('slug', self::SLUG_TROOSOLAR)
                    ->orWhereRaw('LOWER(name) = ?', ['troosolar']);
            })
            ->first();

        if ($partner) {
            if ($partner->slug !== self::SLUG_TROOSOLAR) {
                $partner->slug = self::SLUG_TROOSOLAR;
                $partner->save();
            }

            return $partner;
        }

        return static::create([
            'name' => 'Troosolar',
            'slug' => self::SLUG_TROOSOLAR,
            'email' => 'bnpl@troosolar.com',
            'status' => 'Active',
            'no_of_loans' => 0,
            'amount' => 0,
        ]);
    }
}
