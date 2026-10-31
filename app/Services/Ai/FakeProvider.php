<?php

namespace App\Services\Ai;

/**
 * Demo provider used when no Gemini key is configured. Text calls are
 * answered by each caller's template fallback (see Ai::json); images are
 * drawn locally with GD so the design studio works end to end.
 */
class FakeProvider implements AiProvider
{
    private const DEFAULT_PALETTE = ['#1B3A5C', '#C9A227', '#E8DCC4', '#2F6F73', '#B5534A'];

    public function name(): string
    {
        return 'fake';
    }

    public function textModel(): string
    {
        return 'fake';
    }

    public function imageModel(): string
    {
        return 'fake';
    }

    public function json(string $system, string $prompt, array $schema, array $images = []): AiResponse
    {
        return new AiResponse([], 'fake');
    }

    public function image(string $prompt, ?array $source = null, string $aspectRatio = '1:1'): AiImageResponse
    {
        [$w, $h] = $this->dimensions($aspectRatio);
        $seed = crc32($prompt);
        mt_srand($seed);

        $palette = $this->paletteFrom($prompt);
        $img = imagecreatetruecolor($w, $h);
        imageantialias($img, true);

        // Soft vertical gradient background from a light tint of the palette.
        [$r, $g, $b] = $this->rgb($palette[2] ?? '#F4EFE6');
        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $c = imagecolorallocate($img, (int) min(255, $r + 30 * (1 - $t)), (int) min(255, $g + 30 * (1 - $t)), (int) min(255, $b + 30 * (1 - $t)));
            imageline($img, 0, $y, $w, $y, $c);
        }

        $cx = (int) ($w / 2);
        $cy = (int) ($h / 2);
        $size = (int) (min($w, $h) * 0.56);

        // Floor shadow.
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 105);
        imagefilledellipse($img, $cx, $cy + (int) ($size * 0.58), (int) ($size * 0.9), (int) ($size * 0.12), $shadow);

        // Product body: one of a few silhouettes, picked by seed.
        $main = $this->allocate($img, $palette[0]);
        $accent = $this->allocate($img, $palette[1] ?? $palette[0]);
        $shape = mt_rand(0, 2);
        $x1 = $cx - (int) ($size / 2);
        $y1 = $cy - (int) ($size / 2);

        match ($shape) {
            0 => $this->roundedRect($img, $x1, $y1, $x1 + $size, $y1 + $size, (int) ($size * 0.12), $main),
            1 => imagefilledellipse($img, $cx, $cy, $size, $size, $main),
            default => $this->roundedRect($img, $x1 + (int) ($size * 0.15), $y1, $x1 + (int) ($size * 0.85), $y1 + $size, (int) ($size * 0.3), $main),
        };

        // Surface pattern clipped roughly to the body area.
        $pattern = mt_rand(0, 2);
        $step = (int) ($size / 9);
        for ($i = -$size; $i < $size * 2; $i += $step) {
            for ($j = 0; $j < $size; $j += $step) {
                $px = $x1 + $i;
                $py = $y1 + $j;
                if (! $this->inside($img, $px, $py, $main)) {
                    continue;
                }
                match ($pattern) {
                    0 => imagefilledellipse($img, $px, $py, (int) ($step * 0.28), (int) ($step * 0.28), $accent),
                    1 => imagefilledpolygon($img, [$px, $py - (int) ($step * 0.25), $px + (int) ($step * 0.25), $py, $px, $py + (int) ($step * 0.25), $px - (int) ($step * 0.25), $py], $accent),
                    default => imagefilledrectangle($img, $px, $py, $px + (int) ($step * 0.5), $py + 2, $accent),
                };
            }
        }

        // Accent band for a "print area".
        $band = $this->allocate($img, $palette[3] ?? $palette[1] ?? $palette[0]);
        imagefilledrectangle($img, $cx - (int) ($size * 0.22), $cy - (int) ($size * 0.05), $cx + (int) ($size * 0.22), $cy + (int) ($size * 0.05), $band);

        ob_start();
        imagepng($img, null, 6);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return new AiImageResponse($bytes, 'image/png', 'fake');
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensions(string $ratio): array
    {
        return match ($ratio) {
            '9:16' => [576, 1024],
            '4:5' => [820, 1024],
            '16:9' => [1024, 576],
            '3:4' => [768, 1024],
            default => [1024, 1024],
        };
    }

    /**
     * @return list<string>
     */
    private function paletteFrom(string $prompt): array
    {
        preg_match_all('/#[0-9a-fA-F]{6}/', $prompt, $m);
        $colors = array_values(array_unique($m[0]));

        return count($colors) >= 2 ? $colors : array_merge($colors, self::DEFAULT_PALETTE);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private function allocate(\GdImage $img, string $hex): int
    {
        [$r, $g, $b] = $this->rgb($hex);

        return (int) imagecolorallocate($img, $r, $g, $b);
    }

    private function roundedRect(\GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }

    private function inside(\GdImage $img, int $x, int $y, int $color): bool
    {
        return $x >= 0 && $y >= 0 && $x < imagesx($img) && $y < imagesy($img) && imagecolorat($img, $x, $y) === $color;
    }
}
