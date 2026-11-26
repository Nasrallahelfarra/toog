<?php

namespace App\Services\Team;

/**
 * Each staff member's rating out of 100, against the rest of the team.
 *
 * Four parts, each scored against the team's typical member (the median):
 * equal to the median is 50, twice it or more is 100, nothing is 0.
 *
 * - sales (40): units sold per month on the team;
 * - success (25): share of their new products that sold in their first
 *   two months, out of those old enough to judge;
 * - uploads (20): products uploaded per month on the team;
 * - status (15): share of their products moving today (slowing counts half).
 *
 * Money never counts: stores that hide revenue rate the same way. A part a
 * member has no data for (no product old enough, none classified yet) is
 * left out and the others share its weight. A member with too little to go
 * on is "being rated" rather than given a low score.
 */
final class TeamRating
{
    /** @var array<string, int> */
    public const WEIGHTS = ['sales' => 40, 'success' => 25, 'uploads' => 20, 'status' => 15];

    public const MIN_PRODUCTS = 5;

    public const MIN_DAYS = 30;

    /** New products old enough to judge before the success part counts. */
    public const MIN_JUDGED = 3;

    /** Rated members needed for a comparison to mean anything. */
    public const MIN_TEAM = 2;

    /**
     * @param  array<int, array{reason: ?string, sales: float, success: ?float, uploads: float, status: ?float}>  $members  keyed by member id
     * @return array<int, array{score: ?int, grade: ?string, reason: ?string, parts: list<array{key: string, weight: int, score: int, value: float, team: float}>}>
     */
    public static function rate(array $members): array
    {
        $eligible = array_filter($members, fn (array $m) => $m['reason'] === null);

        if (count($eligible) < self::MIN_TEAM) {
            return array_map(fn (array $m) => self::unrated($m['reason'] ?? 'small_team'), $members);
        }

        $medians = [];
        foreach (array_keys(self::WEIGHTS) as $part) {
            $values = array_values(array_filter(array_column($eligible, $part), fn ($v) => $v !== null));
            $medians[$part] = $values === [] ? null : self::median($values);
        }

        $out = [];
        foreach ($members as $id => $member) {
            if ($member['reason'] !== null) {
                $out[$id] = self::unrated($member['reason']);

                continue;
            }

            $parts = [];
            foreach (self::WEIGHTS as $part => $weight) {
                $value = $member[$part];
                if ($value === null || $medians[$part] === null) {
                    continue;
                }
                $parts[] = [
                    'key' => $part,
                    'weight' => $weight,
                    'score' => self::partScore((float) $value, (float) $medians[$part]),
                    'value' => round((float) $value, 3),
                    'team' => round((float) $medians[$part], 3),
                ];
            }

            $weights = array_sum(array_column($parts, 'weight'));
            $score = $weights > 0
                ? (int) round(array_sum(array_map(fn (array $p) => $p['score'] * $p['weight'], $parts)) / $weights)
                : null;

            $out[$id] = ['score' => $score, 'grade' => self::grade($score), 'reason' => null, 'parts' => $parts];
        }

        return $out;
    }

    /**
     * 50 at the team's median, 100 at twice it or more. When the typical
     * member has nothing, anyone with something is ahead.
     */
    public static function partScore(float $value, float $median): int
    {
        if ($median <= 0) {
            return $value > 0 ? 100 : 50;
        }

        return (int) round(min(100, 50 * $value / $median));
    }

    public static function grade(?int $score): ?string
    {
        return match (true) {
            $score === null => null,
            $score >= 80 => 'excellent',
            $score >= 60 => 'very_good',
            $score >= 40 => 'average',
            default => 'watch',
        };
    }

    /**
     * @param  list<float|int>  $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * @return array{score: null, grade: null, reason: string, parts: list<never>}
     */
    private static function unrated(string $reason): array
    {
        return ['score' => null, 'grade' => null, 'reason' => $reason, 'parts' => []];
    }
}
