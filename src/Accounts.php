<?php
declare(strict_types=1);
namespace Talixio;

/**
 * AI accounts: other providers or subscriptions a space can use instead of the main AI model (Settings → AI model).
 * A client's space can stay on a European provider while another uses a second Claude subscription, and each
 * account has its own limits. Keys and tokens are kept here and never sent back to the app (has_key, has_token).
 */
final class Accounts
{
    public const KINDS = ['anthropic', 'claude-code', 'openai', 'gemini', 'sovereign'];
    private const KEY = 'model_accounts';

    public function __construct(private App $app) {}

    /** @return list<array> without their secrets */
    public function all(): array
    {
        $used = $this->usage();
        return array_values(array_map(fn(array $a) => self::public($a) + ['spaces' => $used[$a['id']] ?? []], $this->raw()));
    }

    /** One account; with its secrets only for the server's own use. */
    public function get(string $id, bool $secrets = false): ?array
    {
        $a = $this->raw()[$id] ?? null;
        return $a === null ? null : ($secrets ? $a : self::public($a));
    }

    /** Add (no id) or change an account. A key or token left empty keeps the one saved. */
    public function save(array $d, ?string $id = null): array
    {
        $all = $this->raw();
        $old = $id !== null ? ($all[$id] ?? throw new \InvalidArgumentException('no such account')) : null;
        $name = trim(mb_substr((string)($d['name'] ?? ''), 0, 60));
        if ($name === '') throw new \InvalidArgumentException('Give the account a name.');
        foreach ($all as $other) if ($other['id'] !== $id && mb_strtolower($other['name']) === mb_strtolower($name)) throw new \InvalidArgumentException("An account called \"$name\" already exists.");
        $kind = (string)($d['kind'] ?? $old['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) throw new \InvalidArgumentException('kind: ' . implode(', ', self::KINDS));
        $url = rtrim(trim((string)($d['url'] ?? '')), '/');
        if ($url !== '' && !preg_match('#^https?://[^\s]+$#', $url)) throw new \InvalidArgumentException('The address must start with https://');
        $service = (string)($d['service'] ?? '');
        if ($kind === 'sovereign' && !isset(Sovereign::SERVICES[$service])) throw new \InvalidArgumentException('Choose the European provider.');
        if ($kind === 'sovereign' && $url === '' && Sovereign::SERVICES[$service]['url'] === '') throw new \InvalidArgumentException('Paste the address shown in the provider\'s dashboard.');
        $same = $old && $old['kind'] === $kind && ($old['service'] ?? '') === ($kind === 'sovereign' ? $service : '');
        $key = trim((string)($d['key'] ?? ''));
        if ($key === '' && $same) $key = (string)($old['key'] ?? ''); // left empty: unchanged
        $token = trim((string)($d['token'] ?? ''));
        if ($token === '' && $same) $token = (string)($old['token'] ?? '');
        if ($kind === 'claude-code' && $token !== '' && !preg_match('/^[A-Za-z0-9._-]{20,400}$/', $token)) throw new \InvalidArgumentException('Paste the token printed by "claude setup-token".');
        if (in_array($kind, ['anthropic', 'gemini'], true) && $key === '') throw new \InvalidArgumentException('Paste the API key.');
        if ($kind === 'sovereign' && $key === '' && Sovereign::SERVICES[$service]['key']) throw new \InvalidArgumentException('Paste the API key.');
        if ($kind === 'openai' && $key === '' && ($url === '' || str_contains($url, 'api.openai.com'))) throw new \InvalidArgumentException('Paste the API key.');
        if ($kind === 'claude-code' && $token === '') throw new \InvalidArgumentException('Paste the token printed by "claude setup-token".');
        $models = [];
        foreach (Tier::NAMES as $t) {
            $m = trim(mb_substr((string)($d['models'][$t] ?? ''), 0, 120));
            if ($m !== '' && !preg_match('#^[\w.:/@+-]+$#u', $m)) throw new \InvalidArgumentException("The $t model name is not valid.");
            $models[$t] = $m;
        }
        $id ??= $this->newId($name, $all);
        $all[$id] = ['id' => $id, 'name' => $name, 'kind' => $kind, 'service' => $kind === 'sovereign' ? $service : '', 'url' => $url,
            'key' => in_array($kind, ['claude-code'], true) ? '' : $key, 'token' => $kind === 'claude-code' ? $token : '',
            'models' => $models, 'paid' => $kind === 'gemini' && !empty($d['paid']), 'created' => $old['created'] ?? time()];
        $this->write($all);
        foreach (['gemini', 'sovereign'] as $k) $this->app->store->set("{$k}_models_$id", ''); // models listed again with the new key
        Log::info('AI account ' . ($old ? 'changed' : 'added') . ": $name ($kind)");
        $this->app->accountsChanged();
        return self::public($all[$id]);
    }

    /** Remove an account: the spaces that used it go back to the main settings. */
    public function delete(string $id): void
    {
        $all = $this->raw();
        if (!isset($all[$id])) throw new \InvalidArgumentException('no such account');
        $name = $all[$id]['name'];
        unset($all[$id]);
        $this->write($all);
        $st = $this->app->store;
        $st->q("UPDATE spaces SET account = '' WHERE account = ?", [$id]);
        if ($st->get('general_account', '') === $id) $st->set('general_account', '');
        foreach (['gemini', 'sovereign'] as $k) $st->set("{$k}_models_$id", '');
        Log::info("AI account deleted: $name");
        $this->app->accountsChanged();
    }

    /**
     * Checks an account with a short answer from its fast model (European providers: their own check, which also
     * picks the models). Settings not saved yet can be tried: a key left empty is the saved one.
     */
    public function test(array $d, ?string $id = null): array
    {
        $old = $id !== null ? $this->get($id, true) : null;
        $a = ['id' => 'test', 'name' => 'test', 'kind' => (string)($d['kind'] ?? $old['kind'] ?? ''), 'service' => (string)($d['service'] ?? $old['service'] ?? ''),
            'url' => rtrim(trim((string)($d['url'] ?? $old['url'] ?? '')), '/'), 'models' => (array)($d['models'] ?? $old['models'] ?? []), 'paid' => !empty($d['paid'])];
        if (!in_array($a['kind'], self::KINDS, true)) throw new \InvalidArgumentException('kind: ' . implode(', ', self::KINDS));
        $same = $old && $old['kind'] === $a['kind'];
        $a['key'] = trim((string)($d['key'] ?? '')) ?: ($same ? (string)$old['key'] : '');
        $a['token'] = trim((string)($d['token'] ?? '')) ?: ($same ? (string)$old['token'] : '');
        if ($a['kind'] === 'sovereign') return ['ok' => true, 'test' => Sovereign::test($a['service'], $a['key'], $a['url'])];
        $c = $this->app->mainCfg->forAccount($a);
        if ($c->provider === 'gemini') GeminiModels::apply($c, $this->app->store, 'gemini_models_test');
        if (($p = $c->modelProblem()) !== null) throw new \InvalidArgumentException($p === 'no-claude-code' ? 'Claude Code is not installed on this server.' : 'Paste the key first.');
        $saved = $this->app->cfg;
        $this->app->cfg = $c;
        try {
            $reply = Privacy::as('model', fn() => $this->app->provider($c->tier('fast'))->complete('Answer with one word.', '', [['role' => 'user', 'content' => 'Say OK.']], $this->app->tools->only('none'), fn() => null));
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('The provider said no: ' . mb_substr($e->getMessage(), 0, 300));
        } finally { $this->app->cfg = $saved; }
        return ['ok' => true, 'reply' => mb_substr(trim($reply), 0, 80), 'tiers' => array_map(fn(Tier $t) => $t->model, $c->tiers)];
    }

    /** Space ids using each account (0 = General). */
    private function usage(): array
    {
        $st = $this->app->store; $out = [];
        foreach ($st->q("SELECT id, account FROM spaces WHERE account != ''")->fetchAll() as $r) $out[$r['account']][] = (int)$r['id'];
        if (($g = $st->get('general_account', '')) !== '') $out[$g][] = 0;
        return $out;
    }

    private static function public(array $a): array
    {
        $out = array_diff_key($a, ['key' => 1, 'token' => 1]) + ['has_key' => ($a['key'] ?? '') !== '', 'has_token' => ($a['token'] ?? '') !== ''];
        $out['label'] = match ($a['kind']) {
            'anthropic' => 'Anthropic API', 'claude-code' => 'Claude subscription (Claude Code)', 'openai' => 'OpenAI-compatible',
            'gemini' => 'Google Gemini', 'sovereign' => Sovereign::info((string)$a['service'])['name'], default => $a['kind'],
        };
        return $out;
    }

    /** @return array<string,array> */
    private function raw(): array
    {
        $all = json_decode($this->app->store->get(self::KEY, ''), true);
        return is_array($all) ? array_filter($all, fn($a) => is_array($a) && isset($a['id'], $a['kind'], $a['name'])) : [];
    }

    private function write(array $all): void
    {
        uasort($all, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $this->app->store->set(self::KEY, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function newId(string $name, array $all): string
    {
        $base = substr(Spaces::slug($name), 0, 30); if ($base === 'test') $base = 'test-account'; $id = $base; $n = 2;
        while (isset($all[$id])) $id = $base . '-' . $n++;
        return $id;
    }
}
