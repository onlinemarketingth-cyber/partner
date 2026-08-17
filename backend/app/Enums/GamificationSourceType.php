<?php

namespace App\Enums;

// BR-5: the 2 XP sources ("(a) completing learning modules / passing
// certification exams, (b) closing a sale / moving a client through the
// pipeline") broken into the concrete trigger events used by
// gamification_rules.source_type and xp_ledger.source_type.
enum GamificationSourceType: string
{
    case ModuleCompleted = 'module_completed';
    case ExamPassed = 'exam_passed';
    case ReferralSubmitted = 'referral_submitted';
    case PipelineStageAdvanced = 'pipeline_stage_advanced';
    case PaymentComplete = 'payment_complete';
}
