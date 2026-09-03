<?php

use App\Enums\NotificationType;

return [

    'email' => [

        /*
        |----------------------------------------------------------------
        | Which events also send an email
        |----------------------------------------------------------------
        |
        | The test is not "is this event interesting?" — every notification
        | is interesting or it would not exist. It is "would the agent be
        | worse off if they only found out next time they opened the app?"
        |
        | ENABLED. Things with money or access attached, which an agent may
        | be waiting on and cannot discover any other way:
        |   approval_status          — approved / rejected / access changed
        |   commission_paid          — their money has been paid out
        |   order_payment_confirmed  — their customer's payment cleared
        |   announcement             — company news addressed to them
        |
        | DISABLED. exam_passed and exam_failed fire from the agent's OWN
        | submit request: the score is on their screen at the moment the
        | mail would send. Emailing it is a notification about something the
        | reader is currently looking at, which is the definition of spam and
        | is how a sender ends up in a filter — taking the approval and
        | payment mails down with it.
        |
        | follow_up_due already has its own richer mail
        | (FollowUpReminderNotification, which names the client and the last
        | note). Enabling it here would send two mails for one event.
        |
        | reward / system are placeholders with no producer wired yet.
        |
        | BR-7 NOTE: this is a config file, not an admin screen. It is a
        | deliberate step better than the values being buried inside a
        | service, and a deliberate step short of the company-level toggle
        | BR-7 ultimately wants. Flagged in the task report; moving it to a
        | settings table later changes only NotificationService::wantsEmail().
        */
        'types' => [
            NotificationType::ApprovalStatus->value => true,
            NotificationType::CommissionPaid->value => true,
            NotificationType::OrderPaymentConfirmed->value => true,
            /*
             * 2026-09-03. Both ENABLED, by the same test as the rest: would
             * the agent be worse off finding out next time they opened the
             * app?
             *
             * order_payment_failed — yes. A customer whose card was declined
             * is waiting for somebody to help them, and the agent is the only
             * person who will call. A day's delay is a lost sale. (Producer
             * side sends at most one per order per day, so a customer
             * fumbling a card three times is still one mail.)
             *
             * order_refund_reported — yes, and more so: their commission may
             * be reversed. Learning that from a balance that changed without
             * explanation is the worst possible version of this.
             */
            NotificationType::OrderPaymentFailed->value => true,
            NotificationType::OrderRefundReported->value => true,
            NotificationType::Announcement->value => true,

            NotificationType::ExamPassed->value => false,
            NotificationType::ExamFailed->value => false,
            NotificationType::FollowUpDue->value => false,
            NotificationType::Reward->value => false,
            NotificationType::System->value => false,
        ],

        /*
        |----------------------------------------------------------------
        | Events that must NOT send inline
        |----------------------------------------------------------------
        |
        | An announcement notifies every targeted agent in one loop inside
        | the admin's POST. Sending inline there is N SMTP round-trips on a
        | single web request — a timeout on any company big enough to matter,
        | with the announcement already saved.
        |
        | These are stamped email_due_at and left for
        | `notifications:send-emails` to sweep in batches. Everything else
        | sends inline (one recipient, one round-trip, after the transaction
        | commits) and therefore needs neither a queue worker nor cron.
        */
        'deferred_types' => [
            NotificationType::Announcement->value,
        ],

        /*
        |----------------------------------------------------------------
        | Sweep behaviour
        |----------------------------------------------------------------
        |
        | batch_size   Rows per sweep run. At the default cadence (every 5
        |              minutes) this clears 2,400/hour, which outruns any
        |              realistic announcement while keeping one run's
        |              worst-case duration bounded.
        | max_attempts A dead address must stop being retried, or one bad
        |              row is mailed forever at every sweep.
        | stale_hours  Nobody wants "your account was approved" arriving two
        |              days late. Past this, the in-app notification stands
        |              on its own and the row is abandoned rather than sent.
        */
        'batch_size' => 200,
        'max_attempts' => 3,
        'stale_hours' => 24,
    ],

];
