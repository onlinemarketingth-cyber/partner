<?php

namespace App\Support\Money;

/**
 * Which currency a company's money is denominated in.
 *
 * ═══ WHAT THIS IS, AND WHAT IT IS EMPHATICALLY NOT ═══
 *
 * It is a LABEL on an amount, so that a figure this system already stores
 * can be displayed, invoiced and reported with the right currency beside it.
 *
 * It is NOT multi-currency. Nothing here converts. There is no exchange
 * rate anywhere in this system and this class does not introduce one — a
 * rate is a business value that changes daily and has to come from a source
 * somebody is accountable for (BR-7 twice over: which source, and who
 * carries the spread). Every amount stays in the currency of the company
 * that owns it, and a company owns exactly one currency.
 *
 * ═══ WHY THE LIST IS RESTRICTED TO TWO-DECIMAL CURRENCIES ═══
 *
 * BR-3 is not "store money as an integer" in the abstract. It is "store
 * money as an integer number of SATANG", and satang are hundredths. The
 * whole codebase divides by 100 at the display layer — migrations, API
 * Resources, every Vue money formatter, every Thai baht string built with
 * number_format($x / 100, 2).
 *
 * Accepting JPY (0 decimals) would make every stored amount a hundred times
 * the real one; accepting KWD (3 decimals) would make it a tenth. Both are
 * silent: nothing would throw, every screen would render a plausible
 * number, and the first symptom would be a payout. A currency picker that
 * offered them would therefore be a lie, so it does not offer them — and
 * this class states the reason, so that whoever adds one later knows it is
 * a storage change and not a list entry.
 *
 * Supporting a non-hundredth currency means a `minor_unit_digits` column
 * consulted by every formatter, in both frontends. That is a real piece of
 * work; it is not this one.
 *
 * ═══ HOW THIS LIST WAS CHOSEN ═══
 *
 * ISO 4217 codes and their exponents are published facts, not business
 * decisions, so naming them here invents nothing. WHICH of them the
 * platform offers is a business decision, and this list is deliberately
 * minimal: THB, the currency every existing company uses, plus the
 * neighbours a Thai operator most plausibly expands into. Adding one is a
 * one-line change and an ADR, which is the right amount of friction for a
 * decision that changes what a tenant's money means.
 */
final class SupportedCurrency
{
    /**
     * code => [name, symbol]. Every entry is a 2-decimal (hundredth)
     * currency — see the class docblock before adding one that is not.
     *
     * @var array<string, array{name: string, symbol: string}>
     */
    private const CURRENCIES = [
        'THB' => ['name' => 'Thai Baht', 'symbol' => '฿'],
        'USD' => ['name' => 'US Dollar', 'symbol' => '$'],
        'SGD' => ['name' => 'Singapore Dollar', 'symbol' => 'S$'],
        'MYR' => ['name' => 'Malaysian Ringgit', 'symbol' => 'RM'],
        'PHP' => ['name' => 'Philippine Peso', 'symbol' => '₱'],
        'AUD' => ['name' => 'Australian Dollar', 'symbol' => 'A$'],
        'EUR' => ['name' => 'Euro', 'symbol' => '€'],
        'GBP' => ['name' => 'Pound Sterling', 'symbol' => '£'],
    ];

    /**
     * The currency a company has when nobody has said otherwise.
     *
     * Not a guess: every company on this system today is a Thai company
     * storing satang, and the column's default has to reproduce that
     * exactly. A tenant that is not Thai says so.
     */
    public const DEFAULT = 'THB';

    /**
     * Every minor unit in this system is a hundredth — see the class
     * docblock. Named rather than inlined so the assumption is greppable
     * (§7, no magic numbers).
     */
    public const MINOR_UNITS_PER_MAJOR = 100;

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::CURRENCIES);
    }

    public static function isSupported(?string $code): bool
    {
        return $code !== null && array_key_exists(strtoupper($code), self::CURRENCIES);
    }

    /**
     * The symbol to print in front of an amount.
     *
     * Falls back to the CODE, never to '฿'. A company whose currency this
     * build does not recognise (a row written by a newer deploy, a
     * hand-edited value) must be shown "USD 1,500.00" — recognisably
     * unstyled — rather than "฿1,500.00", which would be a wrong number
     * presented as a right one.
     */
    public static function symbol(?string $code): string
    {
        $code = strtoupper((string) $code);

        return self::CURRENCIES[$code]['symbol'] ?? ($code !== '' ? $code : self::DEFAULT);
    }

    public static function name(?string $code): string
    {
        $code = strtoupper((string) $code);

        return self::CURRENCIES[$code]['name'] ?? ($code !== '' ? $code : self::DEFAULT);
    }

    /**
     * The picker's options, for the admin console.
     *
     * @return list<array{code: string, name: string, symbol: string}>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::CURRENCIES as $code => $meta) {
            $options[] = ['code' => $code, 'name' => $meta['name'], 'symbol' => $meta['symbol']];
        }

        return $options;
    }
}
