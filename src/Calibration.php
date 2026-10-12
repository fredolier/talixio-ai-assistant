<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Real bills the user reported (read from their provider's console), compared with Talixio's own estimates for the
 * same days. The ratio corrects the estimates (budget, cost reports): with each new bill it gets closer to what the
 * provider really charges. Once a month the assistant asks for the last month's bill.
 */
final class Calibration
{
    private const KEY = 'bill_reports';
    /** The bills the correction is averaged over (the latest ones: prices and habits change). */
    private const LAST = 3;

    public function __construct(private App $app) {}

    /** @return array<array{from:string,to:string,amount:float,currency:string,usd:float,estimate:float,ts:int}> oldest first */
    public function reports(): array
    {
        return json_decode($this->app->store->get(self::KEY, '[]'), true) ?: [];
    }

    /**
     * Real cost / estimated cost over the latest bills, weighted by their size; 1 until a bill is known.
     * Kept within 0.2–5 so that one mistyped amount cannot make the budget meaningless.
     */
    public function factor(): float
    {
        $r = array_slice(array_filter($this->reports(), fn($x) => $x['estimate'] >= 0.01), -self::LAST);
        $est = array_sum(array_column($r, 'estimate'));
        return $est > 0 ? max(0.2, min(5.0, array_sum(array_column($r, 'usd')) / $est)) : 1.0;
    }

    public function toUsd(float $amount, string $currency): float
    {
        return strtoupper($currency) === 'EUR' ? $amount * $this->app->cfg->eurUsd : $amount;
    }

    /**
     * Record a bill. $month: YYYY-MM (a whole month), or $from / $to: YYYY-MM-DD (both included).
     * Default: last month. A bill for the same period replaces the earlier one.
     * @return array the report, with its estimate and ratio
     */
    public function report(float $amount, string $currency = 'EUR', string $month = '', string $from = '', string $to = ''): array
    {
        if ($amount < 0) throw new \InvalidArgumentException('the amount cannot be negative');
        $currency = strtoupper(trim($currency)) ?: 'EUR';
        if (!in_array($currency, ['EUR', 'USD'], true)) throw new \InvalidArgumentException('currency: EUR or USD');
        if ($from === '' || $to === '') {
            $month = $month !== '' ? $month : date('Y-m', strtotime('first day of last month'));
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) throw new \InvalidArgumentException('month: YYYY-MM');
            $from = "$month-01"; $to = date('Y-m-t', strtotime($from));
        }
        $a = strtotime("$from 00:00"); $b = strtotime("$to 00:00");
        if ($a === false || $b === false || $b < $a) throw new \InvalidArgumentException('dates: YYYY-MM-DD, from before to');
        $estimate = $this->app->store->spentBetween($a, $b + 86400);
        $r = ['from' => $from, 'to' => $to, 'amount' => round($amount, 2), 'currency' => $currency, 'usd' => round($this->toUsd($amount, $currency), 4),
            'estimate' => round($estimate, 4), 'ts' => time()];
        $all = array_values(array_filter($this->reports(), fn($x) => !($x['from'] === $from && $x['to'] === $to)));
        $all[] = $r;
        usort($all, fn($x, $y) => strcmp($x['to'], $y['to']));
        $this->app->store->set(self::KEY, json_encode(array_slice($all, -24)));
        return $r + ['ratio' => $estimate >= 0.01 ? $r['usd'] / $estimate : null, 'factor' => $this->factor()];
    }

    /** How the correction stands, in one line for the model and the Activity page ($lang: en or fr). */
    public function describe(string $lang = 'en'): string
    {
        $n = min(self::LAST, count(array_filter($this->reports(), fn($x) => $x['estimate'] >= 0.01)));
        if ($n === 0) return I18n::tl($lang, 'Costs are estimated from list prices; no real bill reported yet.');
        return I18n::tl($lang, $n > 1 ? 'Estimates are corrected ×{factor}, from the last {n} bills you reported.' : 'Estimates are corrected ×{factor}, from the bill you reported.',
            ['factor' => number_format($this->factor(), 2), 'n' => $n]);
    }

    /**
     * The month to ask the user about, or null: from the 3rd of the month (bills are final by then), for last month
     * if it cost something and no bill covers it, at most once a week, and not with a free local model.
     */
    public function due(?int $now = null): ?string
    {
        $now ??= time();
        if ((int)date('j', $now) < 3 || in_array($this->app->cfg->provider, ['echo', 'claude-code'], true)) return null;
        $month = date('Y-m', strtotime('first day of last month', $now));
        foreach ($this->reports() as $r) if (substr($r['to'], 0, 7) === $month) return null;
        if ((int)$this->app->store->get('bill_asked', '0') > $now - 7 * 86400) return null;
        $a = strtotime("$month-01 00:00");
        return $this->app->store->spentBetween($a, strtotime('first day of next month 00:00', $a)) >= 0.05 ? $month : null;
    }

    /** The note added to the turn context when a bill is due; asking counts once the note is sent. */
    public function ask(): string
    {
        $month = $this->due();
        if ($month === null) return '';
        $this->app->store->set('bill_asked', (string)time());
        $label = date('F Y', strtotime("$month-01"));
        return "Cost calibration: after answering, ask the user in one short sentence how much their AI provider actually billed for $label "
            . "(the figure in the provider's console, e.g. Mistral's Billing page), so your cost estimates get closer to the real bill. "
            . "When they give it, record it with usage_bill. If they don't know or don't want to, drop it.";
    }
}
