<?php
declare(strict_types=1);
namespace Talixio;

/** Daily and monthly spending limits. Over 80% the assistant switches to the fast tier; at 100% it stops calling models. */
final class Budget
{
    /** @param float $factor real bill / estimate, learnt from the bills the user reported (Calibration) */
    public function __construct(private Store $store, private float $daily, private float $monthly, private float $factor = 1.0) {}

    public function status(): array
    {
        $today = $this->store->spentSince(strtotime('today')) * $this->factor;
        $month = $this->store->spentSince(strtotime('first day of this month 00:00')) * $this->factor;
        $ratio = max($this->daily > 0 ? $today / $this->daily : 0, $this->monthly > 0 ? $month / $this->monthly : 0);
        return ['today' => $today, 'month' => $month, 'daily_limit' => $this->daily, 'monthly_limit' => $this->monthly,
            'state' => $ratio >= 1 ? 'blocked' : ($ratio >= 0.8 ? 'low' : 'ok'), 'ratio' => $ratio, 'factor' => $this->factor];
    }

    public function describe(): string
    {
        $s = $this->status();
        $lim = fn(float $v, float $l) => '$' . number_format($v, 2) . ($l > 0 ? ' of $' . number_format($l, 2) : ' (no limit)');
        return 'Spent today ' . $lim($s['today'], $s['daily_limit']) . ', this month ' . $lim($s['month'], $s['monthly_limit'])
            . (abs($this->factor - 1) >= 0.005 ? sprintf(' (estimates ×%.2f to match the real bills)', $this->factor) : '')
            . match ($s['state']) { 'low' => '. Budget is running low: you are on the fast tier; avoid delegating.', 'blocked' => '. Budget exhausted.', default => '.' };
    }
}
