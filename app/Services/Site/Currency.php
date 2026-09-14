<?php

namespace App\Services\Site;

use App\Services\Firebase\Firestore;
use Illuminate\Support\Facades\Cache;

/**
 * The currency prices are shown and taken in.
 *
 * From the `currency` collection: `code`, `symbol`, `name`, `decimalDigits`,
 * `symbolAtRight`, `enable`. The live project has Indonesian Rupiah enabled — symbol
 * `Rp`, no decimal places, symbol on the left — and US Dollar switched off.
 *
 * `decimalDigits` matters more than it looks: Rupiah prices are whole numbers, and
 * printing `Rp 1.500.000,00` reads as a foreign site to the customer it is aimed at.
 *
 * A BOOKING SNAPSHOTS ITS OWN `currencyCode` and is always printed in it. Switching the
 * site's currency later must not relabel what a customer already agreed to pay — that
 * is spec §10, and it is why `format()` takes an optional code rather than always using
 * today's setting.
 */
class Currency
{
    private const CACHE_KEY = 'site.currency';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $currencies = null;

    public function __construct(
        private readonly Firestore $firestore,
        private readonly int $cacheSeconds = 300,
    ) {}

    public function forget(): void
    {
        $this->currencies = null;

        Cache::forget(self::CACHE_KEY);
    }

    /** The enabled currency the site trades in. */
    public function code(): string
    {
        return (string) ($this->active()['code'] ?? 'IDR');
    }

    /**
     * A price, written the way the admin configured it.
     *
     * Falls back to a plain code-and-number rather than guessing when the currency is
     * unknown — a booking taken in a currency since removed still has to print.
     */
    public function format(float $amount, ?string $code = null): string
    {
        $currency = $this->find($code) ?? $this->active();

        if (! $currency) {
            return trim(($code ?: '').' '.number_format($amount, 0, '.', ','));
        }

        $digits = (int) ($currency['decimalDigits'] ?? 0);

        /*
         * `,` groups thousands and `.` is the decimal mark, matching the ADMIN PANEL,
         * which prints `Rp 1,000,000` on the same booking this site prices.
         *
         * The Indonesian convention is the reverse, and this file used it until
         * 2026-08-27. It was defensible on its own and wrong in company: an operator and a
         * customer reading the same figure on two screens must not have to translate
         * between them, and the panel is the authority
         * (docs/website-spec.md, and the panel's own money.js).
         *
         * Hardcoded rather than taken from the locale, because the price is in Rupiah
         * whichever language the page is being read in.
         */
        $number = number_format($amount, $digits, '.', ',');
        $symbol = (string) ($currency['symbol'] ?? $currency['code'] ?? '');

        return ($currency['symbolAtRight'] ?? false) === true
            ? $number.' '.$symbol
            : $symbol.' '.$number;
    }

    /**
     * The same formatting rules, for a price a SCRIPT has to write.
     *
     * The running estimate on the booking screen changes as the customer moves a date,
     * and a round trip to the server for every keystroke would be absurd — so the
     * browser formats it. These are the pieces it needs, from the admin's own currency
     * document, so a price written by the script and one written by `format()` cannot
     * come out looking like different currencies.
     *
     * The separators are not among them: `,` for thousands and `.` for decimals is what
     * the panel prints whichever language the page is read in, and `format()` hardcodes
     * them for the same reason.
     *
     * @return array{code: string, symbol: string, decimalDigits: int, symbolAtRight: bool}
     */
    public function parts(): array
    {
        $currency = $this->active();

        return [
            'code' => (string) ($currency['code'] ?? 'IDR'),
            'symbol' => (string) ($currency['symbol'] ?? $currency['code'] ?? ''),
            'decimalDigits' => (int) ($currency['decimalDigits'] ?? 0),
            'symbolAtRight' => ($currency['symbolAtRight'] ?? false) === true,
        ];
    }

    /** @return array<string, mixed>|null */
    private function active(): ?array
    {
        foreach ($this->all() as $currency) {
            if (($currency['enable'] ?? false) === true) {
                return $currency;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function find(?string $code): ?array
    {
        if (! $code) {
            return null;
        }

        foreach ($this->all() as $currency) {
            if (strtoupper((string) ($currency['code'] ?? '')) === strtoupper($code)) {
                return $currency;
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    private function all(): array
    {
        return $this->currencies ??= Cache::remember(
            self::CACHE_KEY,
            $this->cacheSeconds,
            fn () => $this->firestore->collection('currency'),
        );
    }
}
