<?php

namespace Database\Factories;

use App\Models\CertTier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CertTier>
 */
class CertTierFactory extends Factory
{
    protected $model = CertTier::class;

    /**
     * Monotonic per-process counter.
     *
     * Replaces `fake()->unique()->randomElement(['Basic','Intermediate','High'])`,
     * which threw `OverflowException: Maximum retries of 10000 reached` once a
     * single test created more than three tiers (MeTeamTest builds a whole
     * company tree). Faker's unique() rejects-and-retries against a pool it has
     * already drained — with three possible values the fourth call could never
     * succeed, so the failure was guaranteed, not flaky. It only surfaced when a
     * test finally got big enough to ask for a fourth.
     *
     * `cert_tiers.key` is UNIQUE, so uniqueness here is a real requirement, not a
     * nicety — it just has to come from something with an unbounded range.
     */
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $index = ++self::$sequence;

        // Cycle the human-readable part so fixtures still read like real tiers,
        // and make the KEY unique by construction rather than by retrying.
        $label = ['Basic', 'Intermediate', 'High'][($index - 1) % 3];
        $name = $label.' '.$index;

        return [
            'key' => Str::slug($name),
            'name' => $name,
            'sort_order' => 0,
            'is_mandatory' => false,
        ];
    }
}
