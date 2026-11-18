<?php
declare(strict_types=1);
// 1.37.0: "learn this site's API". The browser notes the JSON calls a site's pages make, cleaned of cookies, tokens and
// keys; the model describes them as a spec (never code); once approved, Talixio serves it as a connector that only
// calls that site. Before this, a site without a public API could only be driven page by page with clicks.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Browser, Privacy, SiteApis, Tools};

$d = test_dir('site-api');
$keys = ['TALIXIO_DATA_DIR', 'TALIXIO_PROVIDER', 'TALIXIO_NOTIFY', 'TALIXIO_EMBEDDINGS'];
$saved = array_combine($keys, array_map(fn($k) => getenv($k), $keys));
putenv("TALIXIO_DATA_DIR=$d"); putenv('TALIXIO_PROVIDER=echo'); putenv('TALIXIO_NOTIFY=none'); putenv('TALIXIO_EMBEDDINGS=off');
$app = App::fromEnv(); $app->cfg->notify = []; $app->cfg->approvalWaitSeconds = 1;
$app->cfg->browserAllowPrivate = true; // the test site is local (http://127.0.0.1): allowed where the browser may open local sites, checked at the end
$port = free_port();
$site = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/../fakes/site_api.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
wait_port($port);
$base = "http://127.0.0.1:$port";
$bad = function (array $spec, string $says, bool $http = true): bool { try { SiteApis::validate($spec, $http); return false; } catch (\InvalidArgumentException $e) { return str_contains($e->getMessage(), $says); } };
$ep = fn(array $e) => $e + ['name' => 'search', 'description' => 'Search products', 'method' => 'GET', 'path' => '/api/products'];
$spec = fn(array $over = []) => $over + ['name' => 'shop', 'title' => 'Test Shop', 'base' => $base, 'auth' => ['type' => 'none'], 'endpoints' => [$ep([])]];

// ---- the spec: what may be described, and what the user approves
check('site api: a spec needs https (plain http only where the browser may open local sites)', $bad($spec(['base' => 'http://shop.example']), 'base:', false)
    && SiteApis::validate($spec(['base' => 'https://shop.example/api/'] ))['base'] === 'https://shop.example/api');
check('site api: a base with a login or a query in it is refused', $bad($spec(['base' => 'https://u:p@shop.example']), 'base:') && $bad($spec(['base' => 'https://shop.example/?a=1']), 'base:'));
check('site api: a path cannot climb out or add its own query', $bad($spec(['endpoints' => [$ep(['path' => '/a/../admin'])]]), 'path') && $bad($spec(['endpoints' => [$ep(['path' => '/a?x=1'])]]), 'path')
    && $bad($spec(['endpoints' => [$ep(['path' => '//evil.example/x'])]]), 'path') && $bad($spec(['endpoints' => [$ep(['path' => 'api'])]]), 'path'));
check('site api: a {value} in a path must be a declared path parameter', $bad($spec(['endpoints' => [$ep(['path' => '/api/orders/{id}'])]]), '{id}')
    && SiteApis::validate($spec(['endpoints' => [$ep(['path' => '/api/orders/{id}', 'params' => ['id' => ['type' => 'string']]])]]), true)['endpoints'][0]['params']['id']['in'] === 'path');
check('site api: a spec cannot set the cookie or sign-in headers itself', $bad($spec(['endpoints' => [$ep(['fixed' => ['headers' => ['Cookie' => 'sid=x']]])]]), 'fixed.headers')
    && $bad($spec(['endpoints' => [$ep(['fixed' => ['headers' => ['Authorization' => 'Bearer x']]])]]), 'fixed.headers')
    && $bad($spec(['endpoints' => [$ep(['fixed' => ['headers' => ['X-A' => "1\r\nCookie: x"]]])]]), 'fixed.headers'));
check('site api: every endpoint says what it does; names are plain', $bad($spec(['endpoints' => [$ep(['description' => ''])]]), 'description')
    && $bad($spec(['name' => 'Shop!']), 'name:') && $bad($spec(['endpoints' => [$ep([]), $ep([])]]), 'used twice') && $bad($spec(['endpoints' => []]), 'at least one'));
check('site api: a GET has no body', $bad($spec(['endpoints' => [$ep(['params' => ['q' => ['in' => 'body']]])]]), 'GET has no body'));
$full = SiteApis::validate($spec(['auth' => ['type' => 'cookies'], 'endpoints' => [$ep([]), $ep(['name' => 'add_to_cart', 'method' => 'POST', 'path' => '/api/cart', 'description' => 'Add to the cart'])]]), true);
$sum = SiteApis::summary($full);
check('site api: the approval says where it calls, what only reads and what changes, and how it signs in',
    str_contains($sum, "It only ever calls $base") && preg_match('/Reads \(run without asking\):\n- search: GET \/api\/products/', $sum) === 1
    && preg_match('/Changes \(ask you each time\):\n- add_to_cart: POST \/api\/cart/', $sum) === 1 && str_contains($sum, "your login to 127.0.0.1:$port in the assistant's browser"), $sum);
check('site api: a GET marked as changing something counts as a change', !SiteApis::reads(SiteApis::validate($spec(['endpoints' => [$ep(['changes' => true])]]), true)['endpoints'][0]));

// ---- what the model may read of the calls seen: no cookies, tokens or keys
$sc = SiteApis::scrub(['method' => 'GET', 'url' => 'https://shop.example/api/p?q=lamp&session_token=tok-9f8e7d6c5b4a39281706f5e4d3c2b1a0&page=2', 'status' => 200, 'mime' => 'application/json', 'cookies' => true,
    'body' => json_encode(['items' => array_fill(0, 9, ['name' => 'Lamp', 'api_key' => 'fake_key_0123456789abcdefABCDEFghij', 'desc' => str_repeat('x', 300)]), 'jwt' => 'eyJhbGciOi.eyJzdWIiOiIx.abc'])]);
$txt = json_encode($sc);
check('site api: cleaned call: secret parameters and fields hidden, the rest kept', str_contains($sc['url'], 'q=lamp') && str_contains($sc['url'], 'session_token=<secret>') && str_contains($sc['url'], 'page=2')
    && !str_contains($txt, 'tok-9f8e') && !str_contains($txt, 'fake_key') && !str_contains($txt, 'eyJ') && str_contains($txt, '<secret>'), $txt);
check('site api: cleaned call: a long list shows its first items and its size, long text is cut', str_contains($r9 = json_encode($sc['response']), '9 items') && substr_count($r9, '"name":"Lamp"') === 3 && !str_contains($txt, str_repeat('x', 200)), $r9);
check('site api: cleaned call: says it signed in with cookies, never their values', $sc['signed_in_with'] === 'cookies');
$sp = SiteApis::scrub(['method' => 'POST', 'url' => 'https://shop.example/login', 'post' => 'email=me%40x.fr&password=hunter2&_csrf=abc', 'status' => 302, 'mime' => 'text/html', 'body' => '']);
check('site api: cleaned form body: password and CSRF hidden', str_contains($sp['request_body'], 'password=<secret>') && str_contains($sp['request_body'], '_csrf=<secret>') && !str_contains(json_encode($sp), 'hunter2'), json_encode($sp));
check('site api: a long opaque value is hidden whatever its name; a plain id or date is not', SiteApis::secretValue('a8f5f167f44f4964e6c998dee827110c') && !SiteApis::secretValue('2026-11-18T10:00:00+01:00') && !SiteApis::secretValue('lamp'));

// ---- saving, tools, calls
$apis = $app->siteApis();
$T = new Tools($app);
check('site api: the tools: browser_api_calls only reads; site_api_save asks first', in_array('browser_api_calls', Tools::READS, true) && in_array('site_api_save', Tools::EFFECTS, true) && $T->needsApproval('site_api_save'));
$app->cfg->autoApprove = ['site_api_save'];
$r = $T->run('site_api_save', ['spec' => json_encode(['name' => 'shop', 'title' => 'Test Shop', 'base' => $base, 'auth' => ['type' => 'header', 'name' => 'X-Api-Key', 'prefix' => '', 'help' => 'Account → Developer'], 'endpoints' => [
    ['name' => 'keyed', 'method' => 'GET', 'path' => '/api/keyed', 'description' => 'Check the key', 'fixed' => ['query' => ['lang' => 'fr']]],
    ['name' => 'add_to_cart', 'method' => 'POST', 'path' => '/api/cart', 'description' => 'Add a product to the cart', 'params' => ['product' => ['type' => 'string', 'required' => true], 'qty' => ['type' => 'integer']]],
]])]);
check('site api: saved from a spec sent as JSON text; it says the key is needed and where, never to ask for it', str_starts_with($r, 'saved the connector shop') && str_contains($r, 'mcp_shop_keyed')
    && str_contains($r, 'Settings → Connected accounts → Connectors → Test Shop') && str_contains($r, 'Never ask for the key in the chat'), $r);
check('site api: its tools are there at once, named like a connector\'s; the read runs without asking, the change asks', $T->needsApproval('mcp_shop_add_to_cart') && !$T->needsApproval('mcp_shop_keyed')
    && in_array('mcp_shop_keyed', $T->readOnly, true) && Tools::untrustedSource('mcp_shop_keyed'));
$k = $T->run('mcp_shop_keyed', []);
check('site api: without its key, a call says where the user enters it, and calls nothing', str_starts_with($k, 'error: This connector needs its key') && str_contains($k, 'Account → Developer') && str_contains($k, 'Never ask for it in the chat'), $k);
$apis->setKey('shop', 'k-123');
$raw = (string)file_get_contents("$d/site-apis.json");
check('site api: the key is kept sealed in its file, never shown in the list', !str_contains($raw, 'k-123') === \Talixio\Secrets::enabled() && $apis->list()[0]['has_key'] === true && !str_contains(json_encode($apis->list()), 'k-123'));
check('site api: with its key, the read reaches the site (fixed query kept)', str_contains($T->run('mcp_shop_keyed', []), '"ok":"keyed","lang":"fr"'));
$apis->setKey('shop', 'wrong');
$k = $T->run('mcp_shop_keyed', []);
check('site api: a refused key says to enter a new one in Settings (not in chat)', str_contains($k, 'refused the call (HTTP 401)') && str_contains($k, 'enter a new one in Settings'), $k);
@unlink(sys_get_temp_dir() . '/talixio-site-cart.json');
$app->cfg->autoApprove = ['site_api_save', 'mcp_shop_add_to_cart'];
$T->run('mcp_shop_add_to_cart', ['product' => 'p1', 'qty' => 2]);
check('site api: a change sends its parameters as a JSON body', json_decode((string)@file_get_contents(sys_get_temp_dir() . '/talixio-site-cart.json'), true) === ['product' => 'p1', 'qty' => 2]);
check('site api: a missing required parameter is said, nothing is sent', str_contains($T->run('mcp_shop_add_to_cart', ['qty' => 1]), 'missing argument: product'));
Privacy::begin(); // a new answer (the site's answers above count as written by others: saving after them asks, tested below)
$T->run('site_api_save', ['spec' => ['name' => 'shop', 'base' => $base, 'auth' => ['type' => 'header', 'name' => 'X-Api-Key'], 'endpoints' => [['name' => 'keyed', 'path' => '/api/keyed', 'description' => 'Check']]]]);
check('site api: saving the same name again replaces it and keeps the key entered', count($apis->all()) === 1 && count($apis->get('shop')['endpoints']) === 1 && $apis->get('shop')['key'] !== '');
$app->cfg->autoApprove = ['site_api_save'];
check('site api: after reading a site\'s answer, saving a connector asks even when saving is allowed without asking', (function () use ($T, $base, $apis) {
    Privacy::$untrusted = true; // the answer read the site's answers (the agent loop sets it)
    $r = $T->run('site_api_save', ['spec' => ['name' => 'later', 'base' => $base, 'endpoints' => [['name' => 'ping', 'path' => '/x', 'description' => 'x']]]]);
    return str_contains($r, 'approve') && $apis->get('later') === null;
})(), '');
Privacy::begin();
check('site api: a name already used by a connector in mcp.json is refused', (function () use ($app, $T, $base) {
    $app->mcp()->saveRaw(json_encode(['mcpServers' => ['notes' => ['url' => 'https://notes.example/mcp']]]));
    $r = $T->run('site_api_save', ['spec' => ['name' => 'notes', 'base' => $base, 'endpoints' => [['name' => 'ping', 'path' => '/x', 'description' => 'x']]]]);
    $app->mcp()->saveRaw('{"mcpServers": {}}');
    return str_contains($r, 'already a connector in mcp.json');
})());
Privacy::begin();
$T->run('site_api_save', ['spec' => ['name' => 'qk', 'base' => $base, 'auth' => ['type' => 'query', 'name' => 'api_key'], 'endpoints' => [['name' => 'ping', 'path' => '/api/query-keyed', 'description' => 'Ping']]]]);
$apis->setKey('qk', 'q-456'); $T2 = new Tools($app);
check('site api: a key in the query works too, and the tools come back in the next answer', str_contains($T2->run('mcp_qk_ping', []), '"ok":"query"'));
$s = $apis->get('shop');
$evil = ['name' => 'x', 'method' => 'GET', 'path' => '/{p}', 'description' => 'x', 'params' => ['p' => ['type' => 'string', 'in' => 'path', 'required' => true, 'description' => '']], 'fixed' => [], 'changes' => false];
[, $u] = $apis->build($s, $evil, ['p' => '@evil.example/../../x?y=1#z']);
check('site api: a value put in the path is encoded: the call stays on the site', SiteApis::host($u) === "127.0.0.1:$port" && str_contains($u, '%40evil.example%2F..%2F..%2Fx%3Fy%3D1%23z'), $u);

// cookie sign-in without a browser: the hook stands for the browser's cookie jar
Privacy::begin();
$T->run('site_api_save', ['spec' => ['name' => 'mine', 'base' => $base, 'auth' => ['type' => 'cookies'], 'endpoints' => [
    ['name' => 'orders', 'path' => '/api/orders', 'description' => 'My orders'], ['name' => 'order', 'path' => '/api/orders/{id}', 'description' => 'One order', 'params' => ['id' => ['type' => 'string']]]]]]);
SiteApis::$cookies = fn(string $url) => 'sid=expired';
$r = $T->run('mcp_mine_orders', []);
check('site api: an expired login says so and asks the user to log in again in the browser', str_contains($r, 'the login to 127.0.0.1:' . $port . ' has expired') && str_contains($r, 'log in again'), $r);
SiteApis::$cookies = fn(string $url) => 'sid=good-session-1234567890abcdef1234';
check('site api: with the login, the read gets the user\'s data', str_contains($T->run('mcp_mine_orders', []), '"id":"o2"'));
SiteApis::$cookies = null;

// ---- with the real browser: the calls a page makes, learned and used with the login done there
if (Browser::findBinary('') !== null) {
    $app->cfg->browser = 'auto'; $app->cfg->browserAllowPrivate = true; // the test site is local
    $B = new Tools($app); Privacy::begin(); Privacy::$userSites = ['127.0.0.1' => true];
    $B->run('browser_open', ['url' => "$base/login"]);
    Privacy::begin(); Privacy::$userSites = ['127.0.0.1' => true]; // the user named the site
    $page = $B->run('browser_open', ['url' => "$base/app"]);
    usleep(500000);
    $seen = $B->run('browser_api_calls', ['host' => '127.0.0.1']);
    check('site api: the browser lists the JSON calls the page made, with their answers', str_contains($seen, "\"url\": \"$base/api/products?q=lamp") && str_contains($seen, '"name": "lamp 1"')
        && str_contains($seen, "\"method\": \"POST\",\n        \"url\": \"$base/api/cart") && str_contains($seen, '"product": "p1"'), mb_substr($seen, 0, 1500));
    check('site api: what the model reads of them holds no cookie, token, key or password', !str_contains($seen, 'good-session') && !str_contains($seen, 'tok-9f8e') && !str_contains($seen, 'fake_key')
        && !str_contains($seen, 'hunter2') && str_contains($seen, 'session_token=<secret>'), mb_substr($seen, 0, 1500));
    check('site api: the signed-in call says it went with cookies', preg_match('#/api/orders",\s*"status": 200,\s*"type": "application/json",\s*"signed_in_with": "cookies"#', $seen) === 1, mb_substr($seen, 0, 2500));
    check('site api: browser_api_calls is a read whose result counts as written by others', Tools::untrustedSource('browser_api_calls'));
    $r = $B->run('mcp_mine_order', ['id' => 'o 1/2']);
    check('site api: a cookie connector uses the login done in the assistant\'s browser', str_contains($r, 'HTTP 200') && str_contains($r, '"id":"o 1/2"'), $r);
    Privacy::begin(); Privacy::$userSites = ['127.0.0.1' => true];
    $B->run('browser_open', ['url' => "$base/logout"]);
    check('site api: logged out in the browser, the connector says to log in again', str_contains($B->run('mcp_mine_order', ['id' => 'o1']), 'has expired or is missing'));
    Browser::shutdown();
    $app->cfg->browserAllowPrivate = false;
} else echo "skip site api browser checks: no Chromium\n";

$app->cfg->browserAllowPrivate = false;
$r = (new Tools($app))->run('mcp_mine_orders', []);
check('site api: where the browser may not open local addresses, neither may a learned connector', str_contains($r, 'blocked non-public address'), $r);
check('site api: removed, its tools go with it', $apis->remove('mine') && !str_contains(implode(',', array_column((new Tools($app))->schemas(), 'name')), 'mcp_mine_'));

proc_terminate($site); proc_close($site);
foreach ($saved as $k => $v) putenv($v === false ? $k : "$k=$v");
