<?php

namespace App\Services\Engagement;

use App\Enums\RedemptionStatus;
use App\Enums\RewardType;
use App\Models\RewardItem;
use App\Models\RewardPointLedger;
use App\Models\RewardRedemption;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agent-view IA item 1.5 ("การเคลมแต้มแลกของรางวัล").
 *
 * TASK-042 §1 (BR-7 resolved 2026-07-23): Reward Points are Option B —
 * a currency decoupled from XP (BR-5), backed by its own append-only
 * reward_point_ledger, which mirrors every XP award 1:1 (see
 * GamificationService::awardXp()). calculateAvailablePoints() below
 * spends against that ledger, never against xp_ledger, so redeeming a
 * reward can never affect Level/Leaderboard.
 *
 * Points are reserved at REQUEST time (pending already counts against
 * the balance, not just Approved/Fulfilled) so an Agent can't submit
 * multiple requests that together exceed their real balance. A
 * Rejected request releases its reserved points back (excluded from the
 * sum) — see calculateAvailablePoints().
 */
class RewardRedemptionService
{
    /** @var array<string, list<RedemptionStatus>> Allowed status transitions — same "sequential, no skipping" discipline as CLAUDE.md §4.3's pipeline state machine. */
    private const ALLOWED_TRANSITIONS = [
        'pending' => [RedemptionStatus::Approved, RedemptionStatus::Rejected],
        'approved' => [RedemptionStatus::Fulfilled],
    ];

    public function calculateAvailablePoints(User $user): int
    {
        $totalPoints = RewardPointLedger::where('user_id', $user->id)->sum('points_awarded');

        $reserved = RewardRedemption::where('user_id', $user->id)
            ->whereIn('status', [RedemptionStatus::Pending, RedemptionStatus::Approved, RedemptionStatus::Fulfilled])
            ->sum('points_spent');

        return (int) $totalPoints - (int) $reserved;
    }

    /**
     * @param  array{shipping_recipient_name?: ?string, shipping_phone?: ?string, shipping_address?: ?string}  $shippingData
     *         TASK-042 §2: captured at request time by the agent, required for
     *         physical items by StoreRewardRedemptionRequest — left empty/ignored
     *         for digital items even if the client sends them.
     */
    public function requestRedemption(RewardItem $rewardItem, User $agent, array $shippingData = []): RewardRedemption
    {
        return DB::transaction(function () use ($rewardItem, $agent, $shippingData) {
            // Lock the reward row for the duration of the stock check +
            // insert so two concurrent requests against the last unit of
            // limited stock can't both succeed (classic race condition).
            $rewardItem = RewardItem::whereKey($rewardItem->id)->lockForUpdate()->firstOrFail();

            if (! $rewardItem->is_active) {
                throw ValidationException::withMessages(['reward_item_id' => 'ของรางวัลนี้ปิดใช้งานแล้ว']);
            }

            if ($rewardItem->stock_quantity !== null) {
                $claimed = RewardRedemption::where('reward_item_id', $rewardItem->id)
                    ->whereIn('status', [RedemptionStatus::Pending, RedemptionStatus::Approved, RedemptionStatus::Fulfilled])
                    ->count();

                if ($claimed >= $rewardItem->stock_quantity) {
                    throw ValidationException::withMessages(['reward_item_id' => 'ของรางวัลนี้หมดสต๊อกแล้ว']);
                }
            }

            $available = $this->calculateAvailablePoints($agent);
            if ($available < $rewardItem->cost_points) {
                throw ValidationException::withMessages([
                    'reward_item_id' => "แต้มไม่พอ (มี {$available} ต้องใช้ {$rewardItem->cost_points})",
                ]);
            }

            $isPhysical = $rewardItem->reward_type === RewardType::Physical;

            return RewardRedemption::create([
                'company_id' => $agent->company_id,
                'user_id' => $agent->id,
                'reward_item_id' => $rewardItem->id,
                'points_spent' => $rewardItem->cost_points, // BR-4-style immutable snapshot
                'status' => RedemptionStatus::Pending,
                'requested_at' => now(),
                // Digital items never persist shipping data even if sent —
                // reward_type on the RewardItem is the single source of truth.
                'shipping_recipient_name' => $isPhysical ? ($shippingData['shipping_recipient_name'] ?? null) : null,
                'shipping_phone' => $isPhysical ? ($shippingData['shipping_phone'] ?? null) : null,
                'shipping_address' => $isPhysical ? ($shippingData['shipping_address'] ?? null) : null,
            ]);
        });
    }

    public function decide(RewardRedemption $redemption, RedemptionStatus $newStatus, User $decidedBy, ?string $note = null): RewardRedemption
    {
        $allowed = self::ALLOWED_TRANSITIONS[$redemption->status->value] ?? [];
        if (! in_array($newStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "ไม่สามารถเปลี่ยนสถานะจาก {$redemption->status->value} เป็น {$newStatus->value} ได้",
            ]);
        }

        $redemption->update([
            'status' => $newStatus,
            'decided_by' => $decidedBy->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        return $redemption->fresh(['rewardItem', 'user', 'decidedBy']);
    }

    /**
     * TASK-042 §2: tracking_number is a plain Admin-editable field, not a
     * status-machine transition — settable (and re-settable) any time after
     * the redemption has been Approved, independent of decide()'s
     * ALLOWED_TRANSITIONS table.
     */
    public function updateTrackingNumber(RewardRedemption $redemption, ?string $trackingNumber): RewardRedemption
    {
        if (! in_array($redemption->status, [RedemptionStatus::Approved, RedemptionStatus::Fulfilled], true)) {
            throw ValidationException::withMessages([
                'tracking_number' => 'ระบุเลขพัสดุได้หลังจากอนุมัติคำขอแล้วเท่านั้น',
            ]);
        }

        $redemption->update(['tracking_number' => $trackingNumber]);

        return $redemption->fresh(['rewardItem', 'user', 'decidedBy']);
    }
}
