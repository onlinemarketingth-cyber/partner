<?php

namespace App\Services\Payment;

/**
 * ADR-017 (TASK-054) — builds the EMVCo-standard Thai PromptPay QR payload
 * string from a company's PromptPay proxy id + an amount. Pure and
 * deterministic (no secrets, no I/O): the frontend renders the returned
 * string as a QR image.
 *
 * The payload is a sequence of TLV (Tag + 2-digit Length + Value) fields:
 *   - 00 "01"                      Payload Format Indicator
 *   - 01 "12"                      Point of Initiation Method (12 = dynamic, amount included)
 *   - 29 <Merchant Account Info>   nested TLV:
 *         00 "A000000677010111"    Application ID (AID) for PromptPay
 *         01 <proxy>               phone (normalized 0066xxxxxxxxx) — 10-digit phone
 *         02 <proxy>               national id — 13-digit id
 *   - 53 "764"                     Currency (THB, ISO 4217 numeric)
 *   - 54 <amount>                  Transaction amount, baht with 2 decimals
 *   - 58 "TH"                      Country Code
 *   - 63 <CRC>                     CRC16-CCITT (0xFFFF, poly 0x1021) over the
 *                                  whole payload including "6304"
 *
 * BR-3: the input amount is satang (integer); it's divided by 100 only to
 * format the EMVCo baht.decimal amount field — money itself is never stored
 * as a float.
 */
class PromptPayService
{
    private const AID_PROMPTPAY = 'A000000677010111';

    public function payload(string $promptpayId, int $amountSatang): string
    {
        $proxyDigits = preg_replace('/\D/', '', $promptpayId);

        if ($proxyDigits === '' || $proxyDigits === null) {
            return '';
        }

        $merchantAccountInfo = $this->tlv('00', self::AID_PROMPTPAY).$this->proxyField($proxyDigits);

        // Baht with exactly 2 decimals (satang / 100), e.g. 890000 -> "8900.00".
        $amountBaht = number_format($amountSatang / 100, 2, '.', '');

        $payload = $this->tlv('00', '01')
            .$this->tlv('01', '12')
            .$this->tlv('29', $merchantAccountInfo)
            .$this->tlv('53', '764')
            .$this->tlv('54', $amountBaht)
            .$this->tlv('58', 'TH');

        // CRC is computed over everything above PLUS the CRC field's own
        // id+length ("6304"), then appended as the value of tag 63.
        $payload .= '6304';
        $payload .= $this->crc16($payload);

        return $payload;
    }

    /**
     * Normalize the PromptPay proxy into the correct nested sub-field:
     *   - 13 digits  -> national id, sub-tag 02, used verbatim
     *   - otherwise  -> treated as a phone number, sub-tag 01, normalized
     *                   to 0066 + the 9 trailing digits (dropping a leading 0)
     */
    private function proxyField(string $proxyDigits): string
    {
        if (strlen($proxyDigits) === 13) {
            return $this->tlv('02', $proxyDigits);
        }

        // Phone: keep the last 9 significant digits, prefix with 0066.
        $phone = '0066'.substr($proxyDigits, -9);

        return $this->tlv('01', $phone);
    }

    /** Build one EMVCo TLV field: 2-char id + zero-padded 2-digit length + value. */
    private function tlv(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /** CRC16-CCITT (0xFFFF init, polynomial 0x1021), returned as 4 upper-case hex chars. */
    private function crc16(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                if ($crc & 0x8000) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
