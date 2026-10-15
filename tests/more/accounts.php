<?php
declare(strict_types=1);
// AI accounts: each space can use its own provider or subscription instead of the main settings.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, ClaudeCodeProvider, Config, Tier};

$d = test_dir('accounts');
$keep = [];
foreach (['TALIXIO_DATA_DIR' => $d, 'TALIXIO_PROVIDER' => 'claude-code', 'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_NOTIFY' => 'none', 'TALIXIO_SEARCH' => 'auto'] as $k => $v) { $keep[$k] = getenv($k); putenv("$k=$v"); }
$app = App::fromEnv();
$err = function (callable $f): string { try { $f(); return ''; } catch (\Throwable $e) { return $e->getMessage(); } };
$acc = $app->accounts();
$tok = str_repeat('sk-ant-oat01-abc', 3);

// ---- Config::forAccount, per kind
$main = $app->mainCfg;
$m3 = ['fast' => 'f1', 'standard' => 's1', 'deep' => 'd1'];
$c = $main->forAccount(['id' => 'a', 'kind' => 'anthropic', 'key' => 'k1', 'url' => 'https://proxy.example/', 'models' => $m3]);
check('accounts: an Anthropic account sets its key, address and models', $c->provider === 'anthropic' && $c->anthropicKey === 'k1' && $c->anthropicBaseUrl === 'https://proxy.example'
    && $c->tier('fast')->model === 'f1' && $c->tier('deep')->model === 'd1' && $c->account === 'a');
check('accounts: the main settings are left as they were', $main->provider === 'claude-code' && $main->account === '' && $main->tier('fast')->model !== 'f1');
$c = $main->forAccount(['id' => 'o', 'kind' => 'openai', 'key' => '', 'url' => 'http://127.0.0.1:11434/v1', 'models' => $m3]);
check('accounts: an OpenAI-compatible local server needs no key, and loses the built-in search', $c->openaiBaseUrl === 'http://127.0.0.1:11434/v1' && $c->modelProblem() === null && $c->search !== 'builtin');
$c = $main->forAccount(['id' => 'g', 'kind' => 'gemini', 'key' => 'gk', 'paid' => true, 'models' => $m3]);
check('accounts: a Gemini account uses its OpenAI-compatible address', $c->openaiKey === 'gk' && str_ends_with($c->openaiBaseUrl, '/openai') && $c->geminiPaid);
$c = $main->forAccount(['id' => 'm', 'kind' => 'sovereign', 'service' => 'mistral', 'key' => 'mk', 'models' => $m3]);
check('accounts: a European account uses its service', $c->sovereign === 'mistral' && $c->sovereignUrl === 'https://api.mistral.ai/v1' && $c->openaiKey === 'mk' && $c->openaiBaseUrl === 'https://api.mistral.ai/v1');
$c = $main->forAccount(['id' => 'p', 'kind' => 'claude-code', 'token' => $tok, 'models' => []]);
check('accounts: a Claude subscription keeps its token and default models', $c->claudeToken === $tok && $c->tier('standard')->model === Config::defaultModels('claude-code')[1]);
$c->tier('fast')->model = 'changed';
check('accounts: tiers are copied, not shared', $main->tier('fast')->model !== 'changed');

// ---- ClaudeCodeProvider: the subscription's token, never an API key
putenv('ANTHROPIC_API_KEY=should-go');
$p = new ClaudeCodeProvider('claude', new Tier('fast', 'x', 6), false, '', $tok);
$env = $p->env();
check('accounts: Claude Code gets the token and no API key', ($env['CLAUDE_CODE_OAUTH_TOKEN'] ?? '') === $tok && !isset($env['ANTHROPIC_API_KEY']));
check('accounts: without a token Claude Code keeps its own environment', (new ClaudeCodeProvider('claude', new Tier('fast', 'x', 6), false, '', ''))->env() === null);
putenv('ANTHROPIC_API_KEY');

// ---- saving: checks, and no secret goes back
check('accounts: a name is needed', $err(fn() => $acc->save(['kind' => 'anthropic', 'key' => 'k'])) === 'Give the account a name.');
check('accounts: an Anthropic key is needed', $err(fn() => $acc->save(['name' => 'X', 'kind' => 'anthropic'])) === 'Paste the API key.');
check('accounts: a bad token is refused', $err(fn() => $acc->save(['name' => 'X', 'kind' => 'claude-code', 'token' => 'no spaces allowed'])) === 'Paste the token printed by "claude setup-token".');
check('accounts: the European provider must be known', $err(fn() => $acc->save(['name' => 'X', 'kind' => 'sovereign', 'service' => 'nope', 'key' => 'k'])) === 'Choose the European provider.');
check('accounts: a bad model name is refused', $err(fn() => $acc->save(['name' => 'X', 'kind' => 'anthropic', 'key' => 'k', 'models' => ['fast' => 'a b']])) === 'The fast model name is not valid.');
$pro = $acc->save(['name' => 'Pro', 'kind' => 'claude-code', 'token' => $tok]);
$provite = $acc->save(['name' => 'Provite', 'kind' => 'claude-code', 'token' => strrev($tok)]);
$mis = $acc->save(['name' => 'Mistral clients', 'kind' => 'sovereign', 'service' => 'mistral', 'key' => 'mis-secret', 'models' => $m3]);
check('accounts: two subscriptions can live side by side', $pro['id'] === 'pro' && $provite['id'] === 'provite' && $pro['has_token'] && !isset($pro['token']));
check('accounts: the same name twice is refused', $err(fn() => $acc->save(['name' => 'pro', 'kind' => 'claude-code', 'token' => $tok])) === 'An account called "pro" already exists.');
$json = json_encode($acc->all());
check('accounts: the list holds no key or token', !str_contains($json, 'mis-secret') && !str_contains($json, $tok) && str_contains($json, '"has_key":true'));
$acc->save(['name' => 'Mistral clients', 'kind' => 'sovereign', 'service' => 'mistral', 'key' => '', 'models' => $m3], 'mistral-clients');
check('accounts: a key left empty is kept', $acc->get('mistral-clients', true)['key'] === 'mis-secret');
check('accounts: a key left empty is not kept when the service changes', $err(fn() => $acc->save(['name' => 'Mistral clients', 'kind' => 'sovereign', 'service' => 'scaleway', 'key' => '', 'models' => $m3], 'mistral-clients')) === 'Paste the API key.');

// ---- spaces
$sp = $app->spaces();
$s1 = $sp->save(['name' => 'Client A', 'account' => 'mistral-clients']);
$s2 = $sp->save(['name' => 'Perso', 'account' => 'provite']);
check('accounts: an unknown account is refused for a space', $err(fn() => $sp->save(['name' => 'Bad', 'account' => 'nope'])) === 'no such AI account');
$app->setSpace((int)$s1['id']);
check('accounts: a space on a European account uses it', $app->cfg->provider === 'sovereign' && $app->cfg->account === 'mistral-clients' && $app->cfg->sovereignKey === 'mis-secret');
check('accounts: confidential work is allowed on the European account', $app->privacy()->modelBlocked('confidential') === null);
$app->setSpace((int)$s2['id']);
check('accounts: another space uses the second subscription', $app->cfg->provider === 'claude-code' && $app->cfg->claudeToken === strrev($tok));
check('accounts: confidential work is blocked on a subscription', $app->privacy()->modelBlocked('confidential') === 'confidential');
$app->setSpace(0);
check('accounts: General uses the main settings by default', $app->cfg === $app->mainCfg);
$sp->setGeneralAccount('pro');
$app->setSpace(0);
check('accounts: General can use an account', $app->cfg->claudeToken === $tok && ($sp->general()['account'] ?? '') === 'pro');
check('accounts: the list says which spaces use each account', array_column($acc->all(), 'spaces', 'id')['pro'] === [0] && array_column($acc->all(), 'spaces', 'id')['mistral-clients'] === [(int)$s1['id']]);
$sp->save(['name' => 'Client A', 'color' => $s1['color'], 'account' => ''], (int)$s1['id']);
$log = json_decode($app->store->get('space_log_' . $s1['id'], ''), true) ?: [];
$details = array_column(array_filter($log, fn($e) => $e['what'] === 'account'), 'detail');
check('accounts: a change of account is noted in the history', $details === ['main settings'], json_encode($log));

// ---- deleting: its spaces go back to the main settings
$acc->delete('provite');
$acc->delete('pro');
check('accounts: deleted accounts leave their spaces on the main settings', $sp->accountOf((int)$s2['id']) === '' && $sp->accountOf(0) === ''
    && $app->store->get('general_account', '') === '' && $app->cfg === $app->mainCfg);
check('accounts: deleting an unknown account fails', $err(fn() => $acc->delete('pro')) === 'no such account');
foreach ($keep as $k => $v) putenv($v === false ? $k : "$k=$v");

// ---- the web app
$w = test_dir('accounts-web');
$web = web_app($w, ['TALIXIO_WEB_PASSWORD' => 'a-long-password-123']);
$web('/api/login', ['password' => 'a-long-password-123']);
$r = $web('/api/accounts', ['name' => 'EU', 'kind' => 'sovereign', 'service' => 'mistral', 'key' => 'web-secret', 'models' => $m3]);
check('accounts web: an account is added', ($r['account']['id'] ?? '') === 'eu' && ($r['account']['has_key'] ?? false) === true && !isset($r['account']['key']), json_encode($r));
$r = $web('/api/accounts');
check('accounts web: the list has no secret', $r['_code'] === 200 && count($r['accounts']) === 1 && !str_contains($r['_raw'] ?? json_encode($r), 'web-secret') && in_array('claude-code', $r['kinds'], true));
$r = $web('/api/accounts', ['name' => 'EU', 'kind' => 'sovereign', 'service' => 'mistral', 'key' => 'k']);
check('accounts web: a duplicate is a 400', $r['_code'] === 400 && $r['error'] === 'An account called "EU" already exists.');
$r = $web('/api/spaces', ['name' => 'Client B', 'account' => 'eu']);
$sid = (int)($r['space']['id'] ?? 0);
check('accounts web: a space picks the account', $sid > 0 && ($r['space']['account'] ?? '') === 'eu', json_encode($r));
$r = $web('/api/spaces/0/account', ['account' => 'eu']);
check('accounts web: General picks the account', ($r['space']['account'] ?? '') === 'eu');
$r = $web('/api/accounts/eu', ['name' => 'EU (Mistral)', 'kind' => 'sovereign', 'service' => 'mistral', 'key' => '', 'models' => $m3]);
check('accounts web: renaming keeps the key', ($r['account']['name'] ?? '') === 'EU (Mistral)' && $r['account']['has_key'] === true);
$r = $web('/api/accounts/eu/delete', []);
$sp2 = $web('/api/spaces');
check('accounts web: deleting frees its spaces', ($r['ok'] ?? false) === true && $web('/api/accounts')['accounts'] === [], json_encode($sp2));
