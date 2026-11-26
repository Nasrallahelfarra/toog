<?php

namespace App\Support;

/**
 * A merchant's photo made ready to keep and send: turned upright from the
 * camera's orientation flag, at most 2048 px on its long side, re-encoded
 * as JPEG (which also drops the location and other metadata).
 *
 * The browser already does this before uploading; this is the safety net
 * for photos that arrive untouched (old browsers, pasted files).
 */
class UploadedPicture
{
    public const MAX_SIDE = 2048;

    /**
     * @return array{bytes: string, width: int, height: int}|null null when the file is not a readable picture
     */
    public static function normalize(string $bytes, int $maxSide = self::MAX_SIDE): ?array
    {
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] * $info[1] > InlineImage::MAX_PIXELS) {
            return null;
        }
        if (! function_exists('imagecreatefromstring') || ! ($image = @imagecreatefromstring($bytes))) {
            return null;
        }

        $image = self::upright($image, $info['mime'] === 'image/jpeg' ? $bytes : null);

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxSide / max($width, $height, 1));
        if ($scale < 1) {
            $image = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))) ?: $image;
        }

        // Transparent areas become white, not black.
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        ob_start();
        imagejpeg($canvas, null, 86);

        return ['bytes' => (string) ob_get_clean(), 'width' => imagesx($canvas), 'height' => imagesy($canvas)];
    }

    /**
     * A phone photo is stored sideways with a flag saying how to turn it;
     * apply the flag, since the picture is re-encoded without it.
     */
    private static function upright(\GdImage $image, ?string $jpeg): \GdImage
    {
        if ($jpeg === null || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($jpeg));
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $turned = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $turned ?: $image;
    }
}
