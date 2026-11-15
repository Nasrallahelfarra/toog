<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * An image ready to send to a model inline: JPEG, PNG or WebP only, and
 * downscaled (as JPEG) when its long side is over the limit.
 */
class InlineImage
{
    /** Larger files are not read at all (the upload limit, 8 MB). */
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** Larger pictures are never decoded: a small file can claim huge dimensions. */
    public const MAX_PIXELS = 40_000_000;

    /**
     * @return array{mime: string, data: string}|null
     */
    public static function fromDisk(?string $path, int $maxSide = 1536): ?array
    {
        $disk = Storage::disk('public');
        if (! $path || ! $disk->exists($path) || $disk->size($path) > self::MAX_BYTES) {
            return null;
        }

        return self::fromBytes((string) $disk->get($path), $maxSide);
    }

    /**
     * @return array{mime: string, data: string}|null
     */
    public static function fromBytes(string $bytes, int $maxSide = 1536): ?array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }

        if (max($info[0], $info[1]) > $maxSide && ($jpeg = self::downscale($bytes, $info[0], $info[1], $maxSide)) !== null) {
            return ['mime' => 'image/jpeg', 'data' => base64_encode($jpeg)];
        }

        return ['mime' => $info['mime'], 'data' => base64_encode($bytes)];
    }

    /**
     * The image as JPEG bytes at most $maxSide on its long side, or null
     * when it cannot be decoded.
     */
    public static function jpeg(string $bytes, int $maxSide = 1024): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }

        return self::downscale($bytes, $info[0], $info[1], min($maxSide, max($info[0], $info[1])));
    }

    private static function downscale(string $bytes, int $width, int $height, int $maxSide): ?string
    {
        if ($width * $height > self::MAX_PIXELS) {
            return null;
        }
        if (! function_exists('imagecreatefromstring') || ! ($src = @imagecreatefromstring($bytes))) {
            return null;
        }
        $scale = $maxSide / max($width, $height, 1);
        $dst = $scale < 1 ? imagescale($src, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))) : $src;
        if (! $dst) {
            return null;
        }

        // Transparent PNG or WebP areas become white, not black.
        $canvas = imagecreatetruecolor(imagesx($dst), imagesy($dst));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $dst, 0, 0, 0, 0, imagesx($dst), imagesy($dst));

        ob_start();
        imagejpeg($canvas, null, 85);

        return (string) ob_get_clean();
    }
}
