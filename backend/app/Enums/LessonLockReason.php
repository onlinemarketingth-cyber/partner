<?php

namespace App\Enums;

/**
 * TASK-151 / ADR-031 §2.2, §2.3 — WHY a lesson is locked.
 *
 * ADR-031 §3 is explicit that the reason matters, not just the fact:
 * "the Agent Portal must show *why* something is locked, not just that it
 * is. 'ต้องเรียนบทก่อนหน้าให้จบก่อน' and 'จะเปิดในอีก 3 วัน' are different
 * problems for the learner." One waits; the other goes and finishes a
 * lesson. A single boolean would leave the learner unable to tell which.
 *
 * Deliberately a closed enum rather than a free-text string: the client
 * switches on it (and, for drip, pairs it with `unlocks_at` to phrase the
 * wait), so a typo'd new value would be a silently unhandled UI branch.
 *
 * NOTE what is NOT here: nothing about how far through the previous lesson
 * the learner got. ADR-028 §4 withholds the measurement; these messages name
 * the ACTION, exactly like LessonCompletionGate::blockedMessage().
 */
enum LessonLockReason: string
{
    /**
     * TASK-155 — the lesson, or the Section holding it, is still a DRAFT.
     *
     * Not one of ADR-031's two reasons: this is not a learner who has to wait
     * or study, it is content that was never published. It lives here anyway
     * because reasonFor() is the single choke point every learner-facing
     * Academy route already consults (stream, completion, progress,
     * quiz-attempt), so one case closes all four at once.
     *
     * A draft is normally HIDDEN rather than locked — ModuleController filters
     * it out of the list for Agents. This case is what answers a guessed id.
     * It is therefore the one lock reason a learner should essentially never
     * see, and its message says nothing about drafts: an agent poking at ids
     * learns only that the lesson is not open.
     */
    case NotPublished = 'not_published';

    /** ADR-031 §2.3 — the whole Section has not dripped open yet. */
    case Drip = 'drip';

    /** ADR-031 §2.2 — the previous REQUIRED lesson in this Section is not complete. */
    case SequentialPrevious = 'sequential_previous';

    /**
     * The learner-facing sentence. Thai, because it is rendered verbatim
     * (CLAUDE.md language note: UI copy is Thai; code and keys stay English).
     */
    public function message(): string
    {
        return match ($this) {
            // No date in the string: `unlocks_at` travels beside this on the
            // resource, so the UI can say "เปิดในอีก 3 วัน" with a live
            // countdown instead of a server-rendered date that is stale the
            // moment it is cached.
            // No mention of "ฉบับร่าง": the fact that unpublished material
            // exists at this id is not something a learner is owed.
            self::NotPublished => 'บทเรียนนี้ยังไม่เปิดให้เรียน',
            self::Drip => 'บทเรียนนี้ยังไม่เปิดให้เรียน กรุณารอถึงวันที่กำหนด',
            self::SequentialPrevious => 'ต้องเรียนบทก่อนหน้าให้จบก่อน จึงจะเข้าเรียนบทนี้ได้',
        };
    }
}
