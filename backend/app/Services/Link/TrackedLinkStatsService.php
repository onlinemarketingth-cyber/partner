<?php

namespace App\Services\Link;

use App\Models\TrackedLink;
use App\Models\TrackedLinkVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * TASK-234 — turns visit rows into the numbers the two dashboards show.
 *
 * Separate from TrackedLinkService on purpose. That one WRITES — it mints
 * codes and records visits, and it runs on the public path where a customer
 * is waiting. This one only READS, and only from admin screens. Keeping
 * them apart means a reporting query can never end up on the hot path by
 * accident, and the write service stays small enough to hold in your head.
 */
class TrackedLinkStatsService
{
    /**
     * Everything the per-link detail panel shows.
     *
     * @return array<string, mixed>
     */
    public function forLink(TrackedLink $link): array
    {
        $visits = TrackedLinkVisit::withoutGlobalScopes()
            ->where('tracked_link_id', $link->id)
            ->get(['visited_at', 'referrer_host', 'device_type', 'is_unique']);

        return [
            'click_count' => $link->click_count,
            'unique_click_count' => $link->unique_click_count,
            'conversion_count' => $link->conversion_count,

            // Rate against UNIQUE opens, not total.
            //
            // A customer who reads the page four times before buying is one
            // person who converted, and dividing by four says the link is
            // four times worse than it is. Agents compare these numbers
            // between their own links to decide where to spend their
            // effort, so a denominator that rewards indecisive readers
            // would actively mislead them.
            'conversion_rate' => $link->unique_click_count > 0
                ? round($link->conversion_count / $link->unique_click_count * 100, 1)
                : null,

            'first_clicked_at' => $link->first_clicked_at,
            'last_clicked_at' => $link->last_clicked_at,

            'referrers' => $this->breakdown($visits, 'referrer_host', 'เข้าตรง'),
            'devices' => $this->breakdown($visits, 'device_type', 'ไม่ทราบ'),
            'by_hour' => $this->byHour($visits),
        ];
    }

    /**
     * Where the opens came from / what they were opened on.
     *
     * NULL is reported as a NAMED bucket ("เข้าตรง" / "ไม่ทราบ") rather than
     * dropped. A referrer is null when somebody typed the URL, opened it
     * from a QR code, or came from an app that strips the header — all of
     * which are real traffic. Silently omitting them would make the
     * percentages add up to less than the click count, and the first person
     * to notice would rightly stop trusting the whole panel.
     *
     * @param  Collection<int, TrackedLinkVisit>  $visits
     * @return list<array{label: string, count: int}>
     */
    private function breakdown(Collection $visits, string $column, string $nullLabel): array
    {
        return $visits
            ->groupBy(fn (TrackedLinkVisit $visit) => $visit->getAttribute($column) ?? $nullLabel)
            ->map(fn (Collection $group, string $label) => ['label' => $label, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Opens per hour of the day, 0–23, ALWAYS all 24 buckets.
     *
     * Zero-filled because the shape of the day is the point: a chart that
     * only contains the hours something happened is not a chart of when
     * people read this, it is a chart of when they did, with the quiet
     * hours silently removed and the axis lying about the gap.
     *
     * @param  Collection<int, TrackedLinkVisit>  $visits
     * @return list<array{hour: int, count: int}>
     */
    private function byHour(Collection $visits): array
    {
        $counts = array_fill(0, 24, 0);

        foreach ($visits as $visit) {
            $hour = (int) $visit->visited_at->format('G');
            $counts[$hour]++;
        }

        return array_map(
            fn (int $hour, int $count) => ['hour' => $hour, 'count' => $count],
            array_keys($counts),
            $counts,
        );
    }

    /**
     * The company-wide roll-up, one row per group.
     *
     * @param  Builder<TrackedLink>  $query
     * @return list<array<string, mixed>>
     */
    public function summaryByGroup(Builder $query): array
    {
        return $query
            ->selectRaw('`group`, count(*) as link_count, sum(click_count) as clicks, sum(unique_click_count) as unique_clicks, sum(conversion_count) as conversions')
            ->groupBy('group')
            ->get()
            ->map(fn ($row) => [
                'group' => $row->getAttribute('group')?->value ?? (string) $row->getRawOriginal('group'),
                'label' => $row->getAttribute('group')?->label(),
                'link_count' => (int) $row->getAttribute('link_count'),
                'clicks' => (int) $row->getAttribute('clicks'),
                'unique_clicks' => (int) $row->getAttribute('unique_clicks'),
                'conversions' => (int) $row->getAttribute('conversions'),
            ])
            ->all();
    }
}
