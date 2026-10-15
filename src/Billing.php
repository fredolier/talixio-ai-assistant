<?php
declare(strict_types=1);
namespace Talixio;

/**
 * What the provider really billed, read each day from its cost API with an Admin key (Anthropic, OpenAI; Mistral and
 * Gemini have none: their estimates are corrected from the bills the user reports, Calibration). Complete days replace
 * the estimates in the budget, and real / estimate over the last 30 days corrects the estimates of the days not billed
 * yet (today). Each source is compared with the usage of the same settings: the main ones or one AI account.
 */
final class Billing
{
    public const KINDS = ['anthropic', 'openai'];
    private const KEY = 'billing_real';
    private const EVERY = 6 * 3600;
    private const FETCH_DAYS = 35;
    private const KEEP_DAYS = 100;

    public function __construct(private App $app) {}

    /**
     * Where real costs can be read: the main settings (provider anthropic or openai with an Admin key) and the AI
     * accounts with one. Keys stay on the server.
     * @return array<string,array{id:string,name:string,kind:string,key:string,scope:string,base:string}> by source id ('main' or the account id)
     */
    public function sources(): array
    {
        $out = [];
        $m = $this->app->mainCfg;
        if ($m->provider === 'anthropic' && $m->anthropicAdminKey !== '')
            $out['main'] = ['id' => '', 'name' => 'Anthropic', 'kind' => 'anthropic', 'key' => $m->anthropicAdminKey, 'scope' => $m->anthropicWorkspace, 'base' => $m->anthropicBaseUrl];
        elseif ($m->provider === 'openai' && $m->openaiAdminKey !== '')
            $out['main'] = ['id' => '', 'name' => 'OpenAI', 'kind' => 'openai', 'key' => $m->openaiAdminKey, 'scope' => $m->openaiProject, 'base' => $m->openaiBaseUrl];
        foreach (array_column($this->app->accounts()->all(), 'id') as $id) {
            $a = $this->app->accounts()->get((string)$id, true);
            if (!$a || !in_array($a['kind'], self::KINDS, true) || ($a['admin_key'] ?? '') === '') continue;
            $url = rtrim((string)($a['url'] ?? ''), '/');
            $out[$a['id']] = ['id' => $a['id'], 'name' => $a['name'], 'kind' => $a['kind'], 'key' => $a['admin_key'], 'scope' => (string)($a['billing_scope'] ?? ''),
                'base' => $url ?: ($a['kind'] === 'anthropic' ? 'https://api.anthropic.com' : 'https://api.openai.com/v1')];
        }
        return $out;
    }

    /** Whether the main AI model's real costs are read (then no bill needs to be asked for). */
    public function coversMain(): bool { return isset($this->sources()['main']); }

    /**
     * USD billed per UTC day between $from and $to (unix, day starts).
     * @return array<string,float> Y-m-d => usd
     */
    public function fetch(array $src, int $from, int $to): array
    {
        $days = [];
        $page = null;
        for ($i = 0; $i < 20; $i++) { // pages
            if ($src['kind'] === 'anthropic') {
                $base = preg_replace('#/v1$#', '', rtrim($src['base'], '/'));
                $q = ['starting_at' => gmdate('Y-m-d\TH:i:s\Z', $from), 'ending_at' => gmdate('Y-m-d\TH:i:s\Z', $to), 'bucket_width' => '1d', 'limit' => 31] + ($page ? ['page' => $page] : []);
                $url = "$base/v1/organizations/cost_report?" . http_build_query($q) . '&group_by%5B%5D=workspace_id';
                $headers = ['x-api-key: ' . $src['key'], 'anthropic-version: 2023-06-01'];
            } else {
                $q = ['start_time' => $from, 'end_time' => $to, 'bucket_width' => '1d', 'limit' => 31] + ($page ? ['page' => $page] : []);
                $url = rtrim($src['base'], '/') . '/organization/costs?' . http_build_query($q) . '&group_by%5B%5D=project_id';
                $headers = ['Authorization: Bearer ' . $src['key']];
            }
            [$code, $body] = Privacy::as('billing', fn() => Http::request('GET', $url, $headers, null, 30));
            $d = json_decode($body, true);
            if ($code >= 400 || !is_array($d)) {
                $msg = is_array($d) ? (string)($d['error']['message'] ?? '') : '';
                throw new \RuntimeException(match (true) {
                    $code === 401 || $code === 403 => 'the Admin key was refused (a regular API key cannot read costs)',
                    default => "error $code" . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : ''),
                });
            }
            foreach ((array)($d['data'] ?? []) as $bucket) {
                $start = $src['kind'] === 'anthropic' ? strtotime((string)($bucket['starting_at'] ?? '')) : (int)($bucket['start_time'] ?? 0);
                if (!$start) continue;
                $day = gmdate('Y-m-d', $start);
                $days[$day] ??= 0.0;
                foreach ((array)($bucket['results'] ?? []) as $r) {
                    if ($src['kind'] === 'anthropic') {
                        if ($src['scope'] !== '' && (string)($r['workspace_id'] ?? '') !== $src['scope']) continue;
                        if (strtoupper((string)($r['currency'] ?? 'USD')) !== 'USD') continue;
                        $days[$day] += (float)($r['amount'] ?? 0) / 100; // cents, as a decimal string
                    } else {
                        if ($src['scope'] !== '' && (string)($r['project_id'] ?? '') !== $src['scope']) continue;
                        if (strtolower((string)($r['amount']['currency'] ?? 'usd')) !== 'usd') continue;
                        $days[$day] += (float)($r['amount']['value'] ?? 0);
                    }
                }
            }
            $page = $d['next_page'] ?? null;
            if (empty($d['has_more']) || !$page) break;
        }
        ksort($days);
        return array_map(fn($v) => round($v, 6), $days);
    }

    /** @return array<string,array{name:string,kind:string,days:array<string,float>,synced:int,error:string}> */
    public function data(): array
    {
        return json_decode($this->app->store->get(self::KEY, ''), true) ?: [];
    }

    /**
     * Read the last weeks from each source, at most every 6 hours unless $force. A failing source keeps what it had and
     * says why. @return array the sources' state (status())
     */
    public function sync(bool $force = false, ?int $now = null): array
    {
        $now ??= time();
        $before = $this->data(); $sources = $this->sources();
        $all = array_intersect_key($before, $sources); // removed accounts and keys
        $to = intdiv($now, 86400) * 86400 + 86400; $from = $to - self::FETCH_DAYS * 86400;
        foreach ($sources as $sid => $src) {
            $cur = $all[$sid] ?? ['days' => [], 'synced' => 0, 'error' => ''];
            if (!$force && $now - (int)($cur['tried'] ?? $cur['synced']) < self::EVERY) continue;
            $cur['name'] = $src['name']; $cur['kind'] = $src['kind']; $cur['scope'] = $src['scope']; $cur['tried'] = $now;
            try {
                $days = $this->fetch($src, $from, $to) + (array)$cur['days'];
                ksort($days);
                $cur['days'] = array_filter($days, fn($d) => $d >= gmdate('Y-m-d', $now - self::KEEP_DAYS * 86400), ARRAY_FILTER_USE_KEY);
                $cur['synced'] = $now; $cur['error'] = '';
            } catch (\Throwable $e) {
                $cur['error'] = mb_substr($e->getMessage(), 0, 300);
                Log::warn("billing {$src['name']}: {$cur['error']}");
            }
            $all[$sid] = $cur;
        }
        if ($all !== $before) $this->app->store->set(self::KEY, json_encode($all));
        return $this->status($now);
    }

    /**
     * Complete days (before today, UTC) that have a real figure, per source, between $from and $to.
     * @return array<string,array<string,float>> source id => [Y-m-d => usd]
     */
    private function completeDays(int $from, int $to, int $now): array
    {
        $today = gmdate('Y-m-d', $now); $out = [];
        $sources = $this->sources();
        foreach ($this->data() as $sid => $s) {
            if (!isset($sources[$sid])) continue;
            foreach ((array)($s['days'] ?? []) as $day => $usd) {
                $t = strtotime("$day 00:00 UTC");
                if ($day < $today && $t >= $from && $t + 86400 <= $to) $out[$sid][$day] = (float)$usd;
            }
        }
        return $out;
    }

    private function estimate(string $sid, string $day): float
    {
        $t = strtotime("$day 00:00 UTC");
        return $this->app->store->spentBetween($t, $t + 86400, $sid === 'main' ? '' : $sid);
    }

    /**
     * Real / estimated cost over the complete days of the last 30, null until there are 3 days and 5 cents of estimates
     * (kept within 0.2–5, as the bills' correction).
     */
    public function factor(?int $now = null): ?float
    {
        $now ??= time();
        $real = 0.0; $est = 0.0; $n = 0;
        foreach ($this->completeDays($now - 31 * 86400, $now, $now) as $sid => $days) {
            foreach ($days as $day => $usd) { $real += $usd; $est += $this->estimate($sid, $day); $n++; }
        }
        return $n >= 3 && $est >= 0.05 ? max(0.2, min(5.0, $real / $est)) : null;
    }

    /**
     * For the budget between $from and $to: the estimates that real figures replace, and those figures.
     * @return array{0:float,1:float} [estimate replaced, real]
     */
    public function adjust(int $from, int $to, ?int $now = null): array
    {
        $now ??= time();
        $replaced = 0.0; $real = 0.0;
        foreach ($this->completeDays($from, $to, $now) as $sid => $days) {
            foreach ($days as $day => $usd) { $real += $usd; $replaced += $this->estimate($sid, $day); }
        }
        return [$replaced, $real];
    }

    /** For the Activity page: each source, what it billed this month, when it was read, what went wrong. No key. */
    public function status(?int $now = null): array
    {
        $now ??= time();
        $data = $this->data(); $month = gmdate('Y-m', $now); $out = [];
        foreach ($this->sources() as $sid => $src) {
            $s = $data[$sid] ?? [];
            $days = (array)($s['days'] ?? []);
            $m = array_filter($days, fn($d) => str_starts_with($d, $month), ARRAY_FILTER_USE_KEY);
            $out[] = ['source' => $sid, 'name' => $src['name'], 'kind' => $src['kind'], 'scope' => $src['scope'], 'month' => round(array_sum($m), 2),
                'last_day' => $days ? array_key_last($days) : null, 'synced' => (int)($s['synced'] ?? 0), 'error' => (string)($s['error'] ?? '')];
        }
        return ['sources' => $out, 'factor' => $this->factor($now)];
    }

    /** One line for the model and the Activity page, or '' when no real cost is read. */
    public function describe(string $lang = 'en'): string
    {
        $st = $this->status();
        if (!$st['sources']) return '';
        $parts = array_map(fn($s) => $s['error'] !== '' && !$s['synced'] ? I18n::tl($lang, '{name}: not read ({error})', ['name' => $s['name'], 'error' => $s['error']])
            : I18n::tl($lang, '{name} billed ${usd} this month', ['name' => $s['name'], 'usd' => number_format($s['month'], 2)]), $st['sources']);
        return I18n::tl($lang, 'Real costs read from the provider: {list}.', ['list' => implode('; ', $parts)]);
    }
}
