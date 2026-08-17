<?php

namespace App\Enums;

// ADR-017 (TASK-054) — how the customer pays. This phase supports manual
// bank transfer and PromptPay QR only (both slip-verified); no external
// gateway is wired. Kept as a fixed vocabulary so the UI can render the
// right instructions/QR per method.
enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case PromptPay = 'promptpay';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'โอนเงินผ่านธนาคาร',
            self::PromptPay => 'พร้อมเพย์ (PromptPay)',
        };
    }
}
