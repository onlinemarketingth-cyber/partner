<?php

namespace App\Enums;

// The closed pipeline stage VOCABULARY (CLAUDE.md §4.3, as amended
// 2026-08-08 by ADR-026). Sequential transitions only — enforced in a
// Service, not here. "ongoing_next_meeting" carries the 2nd/3rd/4th
// sub-count via referrals.meeting_number, not extra cases.
//
// ADR-026 §3.2: this enum stays the single source of stage vocabulary,
// and a pipeline_template is an ordered SUBSET of it — never free text
// (ADR-026 §2 Option C: free-text stages would break the enum-cast
// pipeline_stage_logs audit trail and would let an admin build a journey
// with no payment stage, silently killing BR-4 commission).
enum PipelineStage: string
{
    case CompleteRegistered = 'complete_registered';
    case WaitingAppointment = 'waiting_appointment';
    case Finish1stDoctorMeeting = 'finish_1st_doctor_meeting';
    case CompletePayment = 'complete_payment';
    case OngoingNextMeeting = 'ongoing_next_meeting';

    // Post-sale stages — human decision 2026-08-08, ADR-026 §5 Q1
    // (resolved), also recorded in CLAUDE.md §4.3. Optional and unordered
    // AS A GROUP: a template may use any subset in any order, each at
    // most once, and all of them must sit AFTER complete_payment
    // (enforced in PipelineTemplateResolver::assertValidStageSequence()).
    // None of them triggers commission — BR-4 still fires at Complete
    // Payment and nowhere else. They earn the ordinary per-stage XP
    // (BR-5 source (b)), no separate bonus.
    //
    // Thai labels (จัดส่ง / นัดใช้บริการ / ติดตามผล) belong to the UI
    // layer, not this enum — CLAUDE.md §7 keeps code/identifiers English.
    case Delivery = 'delivery';
    case ServiceAppointment = 'service_appointment';
    case FollowUp = 'follow_up';

    public function label(): string
    {
        return match ($this) {
            self::CompleteRegistered => 'Complete Registered',
            self::WaitingAppointment => 'Waiting Appointment',
            self::Finish1stDoctorMeeting => 'Finish 1st Doctor Meeting',
            self::CompletePayment => 'Complete Payment',
            self::OngoingNextMeeting => 'Ongoing Next Meeting',
            self::Delivery => 'Delivery',
            self::ServiceAppointment => 'Service Appointment',
            self::FollowUp => 'Follow Up',
        };
    }

    /**
     * The three post-sale stages as a group (ADR-026 §5 Q1). Kept as one
     * named list so the "must come after complete_payment" invariant and
     * any future post-sale rule read from a single definition instead of
     * re-listing the three cases at each call site (§7 — no magic
     * strings/implicit groupings).
     *
     * @return list<self>
     */
    public static function postSaleStages(): array
    {
        return [self::Delivery, self::ServiceAppointment, self::FollowUp];
    }

    public function isPostSale(): bool
    {
        return in_array($this, self::postSaleStages(), true);
    }

    /**
     * The DEFAULT forward transitions — CLAUDE.md §4.3's original
     * five-stage medical journey ("Sequential Transitions Only" — no
     * skipping, no invalid reverse moves).
     *
     * ADR-026 §3.6 (TASK-133) — renamed from allowedNextStages() and
     * DEMOTED to "the fallback for referrals with no template snapshot"
     * (referrals.pipeline_template_id IS NULL, i.e. every pre-ADR-026
     * row). For a templated referral the truth is its own
     * pipeline_template, read by PipelineService::nextStageFor().
     *
     * The three post-sale cases return an EMPTY list, closing the
     * \UnhandledMatchError gap TASK-132 flagged. Empty is the accurate
     * answer, not a placeholder: the default journey does not contain
     * delivery / service_appointment / follow_up at all, so a referral
     * with no template can never legitimately BE at one of them, and
     * this method has no defensible "next stage" to offer. Callers must
     * treat [] as "no legal move" and refuse (fail-closed, §6), never as
     * "pick something reasonable".
     *
     * @return list<self>
     */
    public function defaultAllowedNextStages(): array
    {
        return match ($this) {
            self::CompleteRegistered => [self::WaitingAppointment],
            self::WaitingAppointment => [self::Finish1stDoctorMeeting],
            self::Finish1stDoctorMeeting => [self::CompletePayment],
            self::CompletePayment => [self::OngoingNextMeeting],
            self::OngoingNextMeeting => [self::OngoingNextMeeting],
            // Post-sale stages are only ever reachable through a template
            // (ADR-026 §5 Q1) — unreachable on the default journey.
            self::Delivery, self::ServiceAppointment, self::FollowUp => [],
        };
    }

    /**
     * The default journey as an ORDERED sequence — the same shape
     * PipelineTemplate::stageSequence() returns, so PipelineService can
     * treat a NULL-template (legacy) referral and a templated one with
     * one piece of logic instead of two divergent ones.
     *
     * DERIVED by walking defaultAllowedNextStages() rather than being a
     * second hardcoded list, so the edges and the sequence can never
     * disagree (§7 — one source of truth). The walk stops on the first
     * repeat, which is what terminates it at OngoingNextMeeting's
     * self-loop.
     *
     * @return list<self>
     */
    public static function defaultSequence(): array
    {
        $sequence = [];
        $stage = self::CompleteRegistered;

        while (! in_array($stage, $sequence, true)) {
            $sequence[] = $stage;

            $next = $stage->defaultAllowedNextStages()[0] ?? null;
            if (! $next) {
                break;
            }

            $stage = $next;
        }

        return $sequence;
    }
}
