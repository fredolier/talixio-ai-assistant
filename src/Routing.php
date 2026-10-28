<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Per-usage model routing: which provider and model answer each usage (fast, standard, deep, voice), as a global
 * default plus per-space overrides. It lets one assistant mix providers — a European small model for quick work,
 * a stronger one for hard reasoning — instead of a single provider for everything.
 *
 * A binding is ['account' => id, 'model' => name]: the account ('' = the main settings) names the provider and its
 * credentials (Accounts / Config::forAccount), the model overrides that account's default tier model ('' = keep it).
 * A usage with no binding falls back to the space's AI account (Spaces::accountOf) or the main settings, so an empty
 * map behaves exactly like before this feature. Resolution (App::tierFor) is: per-space override, then — when the
 * space has no AI account of its own — the global default, then today's path.
 */
final class Routing
{
    private const KEY = 'model_routing';
    /** The usages that can be routed: the three chat tiers plus voice (transcription). */
    public const USAGES = ['fast', 'standard', 'deep', 'voice'];

    public function __construct(private App $app) {}

    /** The stored map: ['global' => [usage => binding], 'spaces' => [spaceId => [usage => binding]]]. */
    public function all(): array
    {
        $raw = json_decode($this->app->store->get(self::KEY, ''), true);
        $out = ['global' => [], 'spaces' => []];
        if (!is_array($raw)) return $out;
        foreach ((array)($raw['global'] ?? []) as $u => $bind) if (($b = self::clean($u, $bind)) !== null) $out['global'][$u] = $b;
        foreach ((array)($raw['spaces'] ?? []) as $sid => $binds) {
            if (!ctype_digit((string)$sid) || !is_array($binds)) continue;
            $box = [];
            foreach ($binds as $u => $bind) if (($b = self::clean($u, $bind)) !== null) $box[$u] = $b;
            if ($box) $out['spaces'][(string)(int)$sid] = $box;
        }
        return $out;
    }

    /** The binding for a usage in a space (per-space override, else the global default), or null when nothing is set. */
    public function resolve(int $space, string $usage): ?array
    {
        if (!in_array($usage, self::USAGES, true)) return null;
        $all = $this->all();
        return $all['spaces'][(string)$space][$usage] ?? $all['global'][$usage] ?? null;
    }

    /**
     * Set or clear one binding. $space null = the global default, else that space's override. $account null clears
     * the binding (fall back); '' is the main settings; anything else must be an existing AI account.
     */
    public function set(?int $space, string $usage, ?string $account, string $model = ''): array
    {
        if (!in_array($usage, self::USAGES, true)) throw new \InvalidArgumentException('usage: ' . implode(', ', self::USAGES));
        if ($account !== null && $account !== '' && !$this->app->accounts()->get($account)) throw new \InvalidArgumentException('no such AI account');
        if ($space !== null && $this->app->spaces()->get($space) === null) throw new \InvalidArgumentException('no such space');
        $all = $this->all();
        if ($space === null) {
            if ($account === null) unset($all['global'][$usage]);
            else $all['global'][$usage] = self::binding($account, $model);
        } else {
            $box = $all['spaces'][(string)$space] ?? [];
            if ($account === null) unset($box[$usage]);
            else $box[$usage] = self::binding($account, $model);
            if ($box) $all['spaces'][(string)$space] = $box; else unset($all['spaces'][(string)$space]);
        }
        return $this->write($all);
    }

    /** Drop every binding that points at an AI account being removed (its usages fall back to the main settings). */
    public function pruneAccount(string $id): void
    {
        $all = $this->all();
        $touched = false;
        foreach ($all['global'] as $u => $b) if ($b['account'] === $id) { unset($all['global'][$u]); $touched = true; }
        foreach ($all['spaces'] as $sid => $box) {
            foreach ($box as $u => $b) if ($b['account'] === $id) { unset($all['spaces'][$sid][$u]); $touched = true; }
            if (empty($all['spaces'][$sid])) unset($all['spaces'][$sid]);
        }
        if ($touched) $this->write($all);
    }

    /** What each usage resolves to in a scope (space, or null = the global default), for the Modèles page. */
    public function effective(?int $space): array
    {
        $all = $this->all();
        $out = [];
        foreach (self::USAGES as $u) {
            $b = $space === null ? ($all['global'][$u] ?? null) : ($all['spaces'][(string)$space][$u] ?? $all['global'][$u] ?? null);
            $overridden = $space !== null && isset($all['spaces'][(string)$space][$u]);
            if ($b === null) { $out[$u] = ['routed' => false, 'overridden' => false]; continue; }
            $cfg = $this->app->accountConfig($b['account']);
            $model = $b['model'] !== '' ? $b['model'] : ($u === 'voice' ? ($cfg->transcribeModel ?? '') : ($cfg?->tier($u)->model ?? ''));
            $out[$u] = ['routed' => true, 'overridden' => $overridden, 'account' => $b['account'],
                'provider' => $cfg?->provider ?? '', 'model' => $model];
        }
        return $out;
    }

    private static function binding(string $account, string $model): array
    {
        return ['account' => $account, 'model' => trim(mb_substr($model, 0, 120))];
    }

    private static function clean(string $usage, mixed $bind): ?array
    {
        if (!in_array($usage, self::USAGES, true) || !is_array($bind) || !array_key_exists('account', $bind)) return null;
        return self::binding((string)$bind['account'], (string)($bind['model'] ?? ''));
    }

    private function write(array $all): array
    {
        $this->app->store->set(self::KEY, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $all;
    }
}
