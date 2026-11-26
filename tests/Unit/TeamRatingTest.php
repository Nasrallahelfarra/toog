<?php

namespace Tests\Unit;

use App\Services\Team\TeamRating;
use PHPUnit\Framework\TestCase;

class TeamRatingTest extends TestCase
{
    /**
     * @return array{reason: ?string, sales: float, success: ?float, uploads: float, status: ?float}
     */
    private function member(float $sales, ?float $success, float $uploads, ?float $status, ?string $reason = null): array
    {
        return compact('reason', 'sales', 'success', 'uploads', 'status');
    }

    public function test_the_typical_member_scores_50_and_twice_the_typical_scores_100(): void
    {
        $rated = TeamRating::rate([
            1 => $this->member(10, 0.5, 4, 0.6),
            2 => $this->member(20, 1.0, 8, 1.0),
            3 => $this->member(10, 0.5, 4, 0.6),
        ]);

        $this->assertSame(50, $rated[1]['score']);
        $this->assertSame('average', $rated[1]['grade']);
        $this->assertSame(97, $rated[2]['score'], 'twice the median on three parts (100), status 1.0 / 0.6 gives 83: (4000 + 2500 + 2000 + 1245) / 100');
        $this->assertSame('excellent', $rated[2]['grade']);
        $this->assertSame(['sales', 'success', 'uploads', 'status'], array_column($rated[2]['parts'], 'key'));
    }

    public function test_a_member_without_enough_to_go_on_is_being_rated_not_low(): void
    {
        $rated = TeamRating::rate([
            1 => $this->member(10, 0.5, 4, 0.6),
            2 => $this->member(12, 0.4, 3, 0.5),
            3 => $this->member(0, null, 0, null, 'few_products'),
        ]);

        $this->assertNull($rated[3]['score']);
        $this->assertSame('few_products', $rated[3]['reason']);
        $this->assertNotNull($rated[1]['score']);
    }

    public function test_one_rated_member_is_not_compared_with_anyone(): void
    {
        $rated = TeamRating::rate([
            1 => $this->member(10, 0.5, 4, 0.6),
            2 => $this->member(0, null, 0, null, 'disabled'),
        ]);

        $this->assertNull($rated[1]['score']);
        $this->assertSame('small_team', $rated[1]['reason']);
        $this->assertSame('disabled', $rated[2]['reason']);
    }

    public function test_a_part_without_data_is_left_out_and_the_others_share_its_weight(): void
    {
        $rated = TeamRating::rate([
            1 => $this->member(10, null, 4, 0.6),
            2 => $this->member(10, 0.5, 4, 0.6),
        ]);

        // Member 1 has no product old enough to judge: 50 on the other three parts.
        $this->assertSame(50, $rated[1]['score']);
        $this->assertNotContains('success', array_column($rated[1]['parts'], 'key'));
    }

    public function test_when_the_typical_member_has_nothing_anyone_with_something_is_ahead(): void
    {
        $this->assertSame(100, TeamRating::partScore(3, 0));
        $this->assertSame(50, TeamRating::partScore(0, 0));
        $this->assertSame(0, TeamRating::partScore(0, 5));
        $this->assertSame(100, TeamRating::partScore(50, 5));
    }

    public function test_grades(): void
    {
        $this->assertSame('excellent', TeamRating::grade(80));
        $this->assertSame('very_good', TeamRating::grade(60));
        $this->assertSame('average', TeamRating::grade(40));
        $this->assertSame('watch', TeamRating::grade(39));
        $this->assertNull(TeamRating::grade(null));
    }
}
