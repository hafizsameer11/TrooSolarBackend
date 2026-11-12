<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PublicMedia
{
    /**
     * Store on the public disk and return a relative key (e.g. products/uuid.jpg).
     */
    public static function store(UploadedFile $file, string $folder): string
    {
        return $file->store(trim($folder, '/'), 'public');
    }

    /**
     * Normalize any stored value (relative key, /storage/..., /storage/public/..., absolute URL)
     * into a public-disk relative key.
     */
    public static function toRelativeKey(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $path = trim($stored);

        if (preg_match('#^https?://[^/]+(/.*)$#i', $path, $m)) {
            $path = $m[1];
        }

        $path = preg_replace('#^/storage/public/#', '', $path) ?? $path;
        $path = preg_replace('#^/storage/#', '', $path) ?? $path;
        $path = preg_replace('#^public/#', '', $path) ?? $path;

        $path = ltrim((string) $path, '/');

        return $path !== '' ? $path : null;
    }

    /**
     * Public URL path suitable for API clients (leading /storage/...).
     */
    public static function toPublicUrl(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $value = trim($stored);
        if (Str::startsWith($value, ['http://', 'https://'])) {
            // Rebuild from path so localhost / wrong APP_URL hosts are not trusted forever.
            if (preg_match('#^https?://[^/]+(/.*)$#i', $value, $m)) {
                $value = $m[1];
            } else {
                return $value;
            }
        }

        $relative = self::toRelativeKey($value);
        if (! $relative) {
            return null;
        }

        return '/storage/' . ltrim($relative, '/');
    }

    public static function delete(?string $stored): void
    {
        $relative = self::toRelativeKey($stored);
        if ($relative && Storage::disk('public')->exists($relative)) {
            Storage::disk('public')->delete($relative);
        }
    }
}
