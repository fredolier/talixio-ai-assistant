<?php
declare(strict_types=1);
// One sign-in across a person's linked assistants (src/Links.php, /sso in src/Web.php).
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Links};

$d = test_dir('sso');
$ka = bin2hex(random_bytes(32)); $kb = bin2hex(random_bytes(32));
$keep = [];
$env = ['TALIXIO_DATA_DIR' => $d, 'TALIXIO_PROVIDER' => 'echo', 'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_NOTIFY' => 'none'];
foreach ($env + ['TALIXIO_INSTANCE' => '', 'TALIXIO_SSO_KEY' => '', 'TALIXIO_LINKS' => '', 'TALIXIO_INSTANCE_NAME' => ''] as $k => $v) { $keep[$k] = getenv($k); putenv("$k=$v"); }
$app = App::fromEnv();
$as = function (string $self, string $key, array $links) { putenv("TALIXIO_INSTANCE=$self"); putenv("TALIXIO_SSO_KEY=$key"); putenv('TALIXIO_LINKS=' . json_encode($links)); };
$tq = fn(string $url): string => substr($url, strpos($url, '?t=') + 3);

// ---- assistant A signs a ticket for B with B's key; B checks it with its own
$as('work', $ka, [['id' => 'home', 'name' => 'Private', 'url' => 'https://acme.example/home/', 'key' => $kb]]);
$l = $app->links();
check('sso: the list never carries a key', $l->list() === [['id' => 'home', 'name' => 'Private', 'url' => 'https://acme.example/home/']]);
$url = $l->ticket('home');
check('sso: a ticket opens /sso on the other assistant', str_starts_with($url, 'https://acme.example/home/sso?t='), $url);
$threw = false; try { $l->ticket('zed'); } catch (\InvalidArgumentException) { $threw = true; }
check('sso: no ticket for an assistant that is not linked', $threw);
check('sso: A does not accept its own ticket for B', $l->verify($tq($url)) === null);
$t = $tq($url); $t2 = $tq($l->ticket('home'));
$as('home', $kb, [['id' => 'work', 'name' => 'Work', 'url' => 'https://acme.example/work/', 'key' => $ka]]);
check('sso: B accepts it once', $l->verify($t) === 'work');
check('sso: ... and never twice', $l->verify($t) === null);
[$p, $s] = explode('.', $t2);
$tamper = rtrim(strtr(base64_encode(str_replace('"f":"work"', '"f":"wurk"', (string)base64_decode(strtr($p, '-_', '+/')))), '+/', '-_'), '=') . ".$s";
check('sso: a changed ticket is refused', $l->verify($tamper) === null && $l->verify('nonsense') === null);
$as('home', $ka, [['id' => 'work', 'name' => 'Work', 'url' => 'https://acme.example/work/', 'key' => $ka]]);
check('sso: a ticket signed with another key is refused', $l->verify($t2) === null);
$as('home', $kb, []);
check('sso: a ticket from an assistant no longer linked is refused', $l->verify($t2) === null);
// expired: sign one by hand
$as('home', $kb, [['id' => 'work', 'name' => 'Work', 'url' => 'https://acme.example/work/', 'key' => $ka]]);
$b64 = fn(string $r): string => rtrim(strtr(base64_encode($r), '+/', '-_'), '=');
$sign = fn(array $p): string => ($x = $b64(json_encode($p))) . '.' . $b64(hash_hmac('sha256', $x, $kb, true));
check('sso: an expired ticket is refused', $l->verify($sign(['f' => 'work', 't' => 'home', 'e' => time() - 1, 'n' => bin2hex(random_bytes(12))])) === null);
check('sso: a ticket for another assistant is refused', $l->verify($sign(['f' => 'work', 't' => 'other', 'e' => time() + 30, 'n' => bin2hex(random_bytes(12))])) === null);
check('sso: a ticket valid too long is refused', $l->verify($sign(['f' => 'work', 't' => 'home', 'e' => time() + 3600, 'n' => bin2hex(random_bytes(12))])) === null);
check('sso: a hand-signed valid ticket passes', $l->verify($sign(['f' => 'work', 't' => 'home', 'e' => time() + 30, 'n' => bin2hex(random_bytes(12))])) === 'work');
putenv('TALIXIO_INSTANCE_NAME=Private');
check('sso: current names this assistant', $l->current() === ['id' => 'home', 'name' => 'Private']);
$as('', '', []);
check('sso: off without links', $l->list() === [] && $l->verify($sign(['f' => 'work', 't' => 'home', 'e' => time() + 30, 'n' => 'ab'])) === null);

// ---- through the web: /sso opens a session, a bad ticket does not
$wd = test_dir('sso-web');
$links = json_encode([['id' => 'work', 'name' => 'Work', 'url' => 'https://acme.example/work/', 'key' => $ka]]);
$web = web_app($wd, ['TALIXIO_WEB_PASSWORD' => 'correct horse battery', 'TALIXIO_INSTANCE' => 'home', 'TALIXIO_SSO_KEY' => $kb, 'TALIXIO_LINKS' => $links, 'TALIXIO_INSTANCE_NAME' => 'Private']);
$bad = $web('/sso?t=nonsense', null, 'x');
check('sso web: a bad ticket goes to the sign-in page', $bad['_code'] === 303 && $web('/api/status', null, 'x')['_code'] === 401, (string)$bad['_code']);
$good = $web('/sso?t=' . $sign(['f' => 'work', 't' => 'home', 'e' => time() + 30, 'n' => bin2hex(random_bytes(12))]), null, 'y');
check('sso web: a good ticket redirects without caching or a referrer', $good['_code'] === 303 && str_contains($good['_headers']['cache-control'] ?? '', 'no-store')
    && ($good['_headers']['referrer-policy'] ?? '') === 'no-referrer', json_encode($good['_headers']));
$st = $web('/api/status', null, 'y');
check('sso web: ... and opens a session', $st['_code'] === 200, (string)$st['_code']);
check('sso web: the status lists the other assistants, without keys', ($st['assistants'][0]['id'] ?? '') === 'work' && !str_contains($st['_raw'], $ka));
$as2 = $web('/api/assistants', null, 'y');
check('sso web: /api/assistants names this one and the others', ($as2['current']['name'] ?? '') === 'Private' && ($as2['assistants'][0]['name'] ?? '') === 'Work', $as2['_raw']);
$go = $web('/api/assistants/go', ['to' => 'work'], 'y');
check('sso web: go returns a ticket address for the other', str_starts_with($go['url'] ?? '', 'https://acme.example/work/sso?t='), $go['_raw']);
check('sso web: go refuses an unknown assistant', $web('/api/assistants/go', ['to' => 'zed'], 'y')['_code'] === 400);
check('sso web: go needs a session', $web('/api/assistants/go', ['to' => 'work'], 'z')['_code'] === 401);

// ---- the installed app: assistants under one address keep switching inside its window
$mf = $web('/manifest.json', null, 'y');
check('sso web: a root assistant keeps its own scope', ($mf['scope'] ?? '') === './', $mf['_raw'] ?? '');
$web2 = web_app(test_dir('sso-web-path'), ['TALIXIO_WEB_PASSWORD' => 'pw', 'TALIXIO_BASE_PATH' => 'home', 'TALIXIO_INSTANCE' => 'home', 'TALIXIO_SSO_KEY' => $kb, 'TALIXIO_LINKS' => $links]);
$mf2 = $web2('/manifest.json');
check('sso web: linked assistants under a path widen the app to the whole address', ($mf2['scope'] ?? '') === '/' && ($mf2['id'] ?? '') === './'
    && ($mf2['start_url'] ?? '') === './?source=app#chat' && str_contains($mf2['_headers']['content-type'] ?? '', 'manifest+json'), $mf2['_raw'] ?? '');
$web3 = web_app(test_dir('sso-web-alone'), ['TALIXIO_WEB_PASSWORD' => 'pw', 'TALIXIO_BASE_PATH' => 'solo']);
check('sso web: an assistant under a path with no links keeps its own scope', ($web3('/manifest.json')['scope'] ?? '') === './');

foreach ($keep as $k => $v) putenv($v === false ? $k : "$k=$v");
