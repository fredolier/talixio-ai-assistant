<?php
declare(strict_types=1);
// Worker, Cli, Spotify, Board, Config, Sovereign, Transcriber, Doctor, BrowserProxy, ConnectorPackage, Updater:
// the branches the main suite leaves out. Outside services are a small scriptable server on 127.0.0.1.
require_once __DIR__ . '/../helpers.php';
use Talixio\{App, Board, Config, ConnectorPackage, Doctor, Sovereign, SpotifyError, Transcriber, Updater, Worker, Cli, Conflict, Http};

$d = test_dir('misc');
$root = dirname(__DIR__, 2);

// ---- the environment, changed per section and put back after
$envKeys = [];
$setEnv = function (array $vars) use (&$envKeys) {
    foreach ($vars as $k => $v) {
        if (!array_key_exists($k, $envKeys)) $envKeys[$k] = getenv($k);
        putenv($v === null ? $k : "$k=$v");
    }
};
$restoreEnv = function () use (&$envKeys) {
    foreach ($envKeys as $k => $v) putenv($v === false ? $k : "$k=$v");
    $envKeys = [];
};
$base = ['TALIXIO_PROVIDER' => 'echo', 'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_NOTIFY' => 'none'];
$n = 0;
/** A fresh app in its own data folder with this environment (left set until $restoreEnv). */
$mkApp = function (array $env = []) use ($d, &$n, $setEnv, $base): App {
    $dir = "$d/app" . (++$n);
    @mkdir($dir, 0777, true);
    $setEnv(['TALIXIO_DATA_DIR' => $dir] + $env + $base);
    return App::fromEnv();
};
$err = function (callable $f): string { try { $f(); return ''; } catch (\Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); } };

// ---- a scriptable server: answers from routes.json ("METHOD /path" or "METHOD /path|param=value" → [code, body, headers]), logs every call
$fakeDir = "$d/fake"; @mkdir($fakeDir);
file_put_contents("$fakeDir/router.php", <<<'PHP'
<?php
$dir = __DIR__;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$m = $_SERVER['REQUEST_METHOD'];
$body = file_get_contents('php://input');
file_put_contents("$dir/log.jsonl", json_encode(['m' => $m, 'path' => $path, 'q' => $_GET, 'body' => !str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart') ? substr($body, 0, 200000) : json_encode($_POST + ['files' => array_keys($_FILES)]), 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? ''], JSON_INVALID_UTF8_SUBSTITUTE) . "\n", FILE_APPEND | LOCK_EX);
$routes = json_decode((string)@file_get_contents("$dir/routes.json"), true) ?: [];
$hit = null;
foreach ($routes as $k => $r) {
    if (!str_contains($k, '|')) continue;
    [$key, $cond] = explode('|', $k, 2);
    [$p, $v] = explode('=', $cond, 2);
    if (($key === "$m $path" || $key === "* $path") && (string)($_GET[$p] ?? '') === $v) { $hit = $r; break; }
}
$hit ??= $routes["$m $path"] ?? $routes["* $path"] ?? [404, ['error' => 'no route']];
http_response_code($hit[0]);
foreach ($hit[2] ?? [] as $h) header($h);
if (!isset($hit[2])) header('Content-Type: application/json');
echo is_string($hit[1]) ? $hit[1] : json_encode($hit[1]);
PHP);
$fport = free_port();
$fenv = ['PATH' => getenv('PATH')] + (getenv('PHP_INI_SCAN_DIR') !== false ? ['PHP_INI_SCAN_DIR' => getenv('PHP_INI_SCAN_DIR')] : []);
$fp = proc_open([PHP_BINARY, '-S', "127.0.0.1:$fport", "$fakeDir/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $fpp, null, $fenv);
register_shutdown_function(function () use ($fp) { proc_terminate($fp); proc_close($fp); });
wait_port($fport);
$F = "http://127.0.0.1:$fport";
$routes = function (array $r) use ($fakeDir) { file_put_contents("$fakeDir/routes.json", json_encode($r)); @unlink("$fakeDir/log.jsonl"); };
$calls = function () use ($fakeDir): array { return array_map(fn($l) => json_decode($l, true), array_filter(explode("\n", (string)@file_get_contents("$fakeDir/log.jsonl")))); };

// ======================= Spotify =======================
$app = $mkApp();
check('spotify: without a client ID, no sign-in link', str_contains($err(fn() => $app->spotify()->authUrl('https://x.acme.example/cb')), 'Fill in the Spotify client ID'));
$restoreEnv();
$app = $mkApp(['TALIXIO_SPOTIFY_CLIENT_ID' => 'cid', 'TALIXIO_SPOTIFY_CLIENT_SECRET' => 'csec', 'TALIXIO_SPOTIFY_AUTH_URL' => "$F/auth/",
    'TALIXIO_SPOTIFY_TOKEN_URL' => "$F/tok", 'TALIXIO_SPOTIFY_API_BASE' => "$F/sp"]);
$restoreEnv();
$sp = $app->spotify();
$spFile = $app->cfg->dataDir . '/spotify.json';
check('spotify: not connected, any call says so', str_contains($err(fn() => $sp->devices()), 'Spotify is not connected'));
$u = $sp->authUrl('https://x.acme.example/api/spotify/callback');
check('spotify: the sign-in link goes to the address set', str_starts_with($u, "$F/auth?client_id=cid"), $u);
$routes(['POST /tok' => [200, ['access_token' => 'a1', 'expires_in' => 3600]]]);
parse_str((string)parse_url($u, PHP_URL_QUERY), $q);
check('spotify: a sign-in without a lasting token is refused', str_contains($err(fn() => $sp->finish('code1', $q['state'])), 'no lasting access'));
$u = $sp->authUrl('https://x.acme.example/api/spotify/callback'); parse_str((string)parse_url($u, PHP_URL_QUERY), $q);
$routes(['POST /tok' => [200, ['access_token' => 'a1', 'refresh_token' => 'r1', 'expires_in' => 3600]], 'GET /sp/me' => [500, ['error' => ['message' => 'down']]]]);
$name = $sp->finish('code2', $q['state']);
$tk = $sp->tokens();
check('spotify: connected even when the account details cannot be read (no name)', $name === '' && $tk['refresh_token'] === 'r1' && $tk['premium'] === null && $sp->connected(), json_encode($tk));
// token refresh
file_put_contents($spFile, json_encode(['refresh_token' => 'r1', 'access_token' => 'old', 'expires' => time() - 10]));
$routes(['POST /tok' => [400, ['error' => 'invalid_grant']]]);
check('spotify: a revoked access is said', str_contains($err(fn() => $sp->devices()), 'revoked or expired'));
$routes(['POST /tok' => [401, ['error' => 'invalid_client', 'error_description' => 'Invalid client secret']]]);
check('spotify: another sign-in failure gives its reason', str_contains($err(fn() => $sp->devices()), 'Spotify sign-in failed: Invalid client secret'));
$routes(['POST /tok' => [500, 'oops']]);
check('spotify: a failure without a reason gives the HTTP code', str_contains($err(fn() => $sp->devices()), 'Spotify sign-in failed: HTTP 500'));
$routes(['POST /tok' => [200, ['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 3600]], 'GET /sp/me/player/devices' => [200, ['devices' => []]]]);
$devs = $sp->devices();
$tk = $sp->tokens(); $c = $calls();
check('spotify: an expired access is renewed, a new lasting token kept, the call made with it', $devs === [] && $tk['refresh_token'] === 'r2' && $tk['access_token'] === 'a2' && $tk['expires'] > time()
    && ($c[1]['auth'] ?? '') === 'Bearer a2', json_encode([$tk, $c]));
// API errors
$apiErr = function (int $code, $body) use ($routes, $sp): ?SpotifyError {
    $routes(['GET /sp/me/player/devices' => [$code, $body]]);
    try { $sp->devices(); } catch (SpotifyError $e) { return $e; }
    return null;
};
$e1 = $apiErr(403, ['error' => ['message' => 'Player command failed: Premium required']]);
$e2 = $apiErr(403, ['error' => ['message' => 'x', 'reason' => 'PREMIUM_REQUIRED']]);
$e3 = $apiErr(404, ['error' => ['message' => 'Device not found']]);
$e4 = $apiErr(404, ['error' => ['message' => 'x', 'reason' => 'NO_ACTIVE_DEVICE']]);
$e5 = $apiErr(500, ['error' => 'boom']);
$e6 = $apiErr(429, 'not json');
check('spotify: errors sorted (premium, device, other with its code)', $e1?->kind === 'premium' && $e2?->kind === 'premium' && $e3?->kind === 'device' && $e4?->kind === 'device'
    && $e5?->kind === 'other' && $e5->getMessage() === 'Spotify: boom' && $e5->getCode() === 500 && $e6?->getMessage() === 'Spotify: HTTP 429',
    json_encode([$e1?->kind, $e2?->kind, $e3?->kind, $e4?->kind, $e5?->getMessage(), $e6?->getMessage()]));
// devices
$kitchen = ['id' => 'd1', 'name' => 'Kitchen speaker', 'type' => 'Speaker', 'volume_percent' => 40];
$phone = ['id' => 'd2', 'name' => "Alex's phone", 'type' => 'Smartphone'];
$laptop = ['id' => 'd3', 'name' => 'Laptop', 'type' => 'Computer'];
$routes(['GET /sp/me/player/devices' => [200, ['devices' => [$kitchen, $phone]]]]);
check('spotify: a device named that does not exist is said, with those there', str_contains($err(fn() => $sp->pickDevice('Garage')), 'No Spotify device called "Garage". Available: Kitchen speaker, Alex\'s phone.'));
check('spotify: a device found by part of its name, or its name containing what was said', $sp->pickDevice('kitchen')['id'] === 'd1' && $sp->pickDevice("alex's phone in the kitchen")['id'] === 'd2'
    && $sp->pickDevice("ALEX'S PHONE")['id'] === 'd2');
check('spotify: nothing active, no usual device: the phone', $sp->pickDevice()['id'] === 'd2');
$app->cfg->spotifyDevice = 'kitchen';
check('spotify: the usual device first', $sp->pickDevice()['id'] === 'd1');
$app->cfg->spotifyDevice = '';
$routes(['GET /sp/me/player/devices' => [200, ['devices' => [$laptop, $kitchen]]]]);
check('spotify: no phone: the first one', $sp->pickDevice()['id'] === 'd3');
$routes(['GET /sp/me/player/devices' => [200, ['devices' => [$laptop, $kitchen + ['is_active' => true]]]]]);
check('spotify: the device playing comes first', $sp->pickDevice()['id'] === 'd1');
// find
$track = ['id' => 't1', 'uri' => 'spotify:track:t1', 'name' => 'Get Lucky', 'artists' => [['name' => 'Daft Punk'], ['name' => 'Pharrell']]];
$album = ['id' => 'al1', 'uri' => 'spotify:album:al1', 'name' => 'Discovery', 'artists' => [['name' => 'Daft Punk']]];
$plist = ['id' => 'pl1', 'uri' => 'spotify:playlist:pl1', 'name' => 'Running'];
$searchRoutes = ['GET /sp/search|type=track' => [200, ['tracks' => ['items' => [null, $track]]]], 'GET /sp/search|type=album' => [200, ['albums' => ['items' => [$album]]]],
    'GET /sp/search|type=playlist' => [200, ['playlists' => ['items' => [['id' => 'pp', 'uri' => 'spotify:playlist:pp', 'name' => 'Public mix']]]]], 'GET /sp/search|type=artist' => [200, ['artists' => ['items' => []]]],
    'GET /sp/me/playlists' => [200, ['items' => [null, $plist, ['id' => 'pl2', 'uri' => 'spotify:playlist:pl2', 'name' => 'Sunday jazz']]]]];
$routes($searchRoutes);
check('spotify: a Spotify address is played as is', $sp->find('spotify:album:abc123') === ['uri' => 'spotify:album:abc123', 'type' => 'album', 'label' => 'spotify:album:abc123'] && $calls() === []);
$f1 = $sp->find('get lucky', 'bogus'); $f2 = $sp->find('discovery', 'album'); $f3 = $sp->find('running', 'playlist'); $f4 = $sp->find('public', 'playlist'); $f5 = $sp->find('nobody', 'artist');
check('spotify: found with what it is (unknown type: a song; your playlists first; nothing found: null)', $f1['label'] === '"Get Lucky" by Daft Punk, Pharrell' && $f1['type'] === 'track'
    && $f2['label'] === 'the album "Discovery" by Daft Punk' && $f3['label'] === 'your playlist "Running"' && $f3['id'] === 'pl1' && $f4['label'] === 'the playlist "Public mix"' && $f5 === null,
    json_encode([$f1, $f2, $f3, $f4, $f5]));
// play
$routes($searchRoutes + ['GET /sp/me/player/devices' => [200, ['devices' => [$kitchen]]], 'POST /sp/me/player/queue' => [204, ''], 'PUT /sp/me/player/play' => [204, '']]);
check('spotify: nothing found to play', $sp->play('nobody', 'artist') === 'Nothing found on Spotify for "nobody".');
check('spotify: only songs go in the queue', $sp->play('discovery', 'album', '', true) === 'Only songs can be added to the queue.' && $sp->play('', 'track', '', true) === 'Only songs can be added to the queue.');
$routes($searchRoutes + ['GET /sp/me/player/devices' => [200, ['devices' => [$kitchen]]], 'POST /sp/me/player/queue' => [204, ''], 'PUT /sp/me/player/play' => [204, '']]);
$out = $sp->play('get lucky', 'track', '', true);
$qc = array_values(array_filter($calls(), fn($x) => $x['path'] === '/sp/me/player/queue'));
check('spotify: a song added to the queue, on the device', $out === 'Added "Get Lucky" by Daft Punk, Pharrell to the queue on Kitchen speaker.' && ($qc[0]['q'] ?? []) === ['uri' => 'spotify:track:t1', 'device_id' => 'd1'], $out . json_encode($qc));
$routes($searchRoutes + ['GET /sp/me/player/devices' => [200, ['devices' => [$kitchen]]], 'PUT /sp/me/player/play' => [204, '']]);
$o1 = $sp->play(''); $o2 = $sp->play('discovery', 'album');
$pc = array_values(array_filter($calls(), fn($x) => $x['path'] === '/sp/me/player/play'));
check('spotify: resume, and an album played as a whole', $o1 === 'Resumed on Kitchen speaker.' && $o2 === 'Playing the album "Discovery" by Daft Punk on Kitchen speaker.'
    && $pc[0]['body'] === '' && json_decode($pc[1]['body'], true) === ['context_uri' => 'spotify:album:al1'], json_encode([$o1, $o2, $pc]));
$routes($searchRoutes + ['GET /sp/me/player/devices' => [200, ['devices' => [$kitchen]]], 'PUT /sp/me/player/play' => [403, ['error' => ['message' => 'x', 'reason' => 'PREMIUM_REQUIRED']]]]);
check('spotify: without Premium, it is said (not "no device")', str_contains($err(fn() => $sp->play('get lucky')), 'needs Spotify Premium'));
$routes($searchRoutes + ['GET /sp/me/player/devices' => [200, ['devices' => []]]]);
$o1 = $sp->play('get lucky'); $o2 = $sp->play('');
check('spotify: no device: a button that opens Spotify at the song (or a search)', str_contains($o1, 'opens Spotify on it: [▶ Open in Spotify](spotify:track:t1)') && str_contains($o2, 'opens Spotify: [▶ Open in Spotify](spotify:search:)'), $o1 . ' | ' . $o2);
// control
$routes(['GET /sp/me/player' => [200, '']]);
check('spotify: control with nothing playing', $sp->control('pause') === 'Nothing is playing on Spotify right now.');
$ctl = ['GET /sp/me/player' => [200, ['device' => $kitchen]], 'GET /sp/me/player/devices' => [200, ['devices' => [$kitchen, $phone]]]];
foreach (['PUT /sp/me/player/pause', 'PUT /sp/me/player/play', 'POST /sp/me/player/next', 'POST /sp/me/player/previous', 'PUT /sp/me/player/volume', 'PUT /sp/me/player/shuffle', 'PUT /sp/me/player/repeat', 'PUT /sp/me/player'] as $k) $ctl[$k] = [204, ''];
$routes($ctl);
$outs = [$sp->control('pause'), $sp->control('resume'), $sp->control('next'), $sp->control('previous'), $sp->control('volume', '+10'), $sp->control('volume', '250'), $sp->control('shuffle', 'Non'),
    $sp->control('shuffle', 'on'), $sp->control('repeat', 'track'), $sp->control('repeat', 'forever'), $sp->control('transfer', 'phone')];
$q = array_map(fn($x) => $x['q'], array_values(array_filter($calls(), fn($x) => $x['m'] === 'PUT' && in_array($x['path'], ['/sp/me/player/volume', '/sp/me/player/shuffle', '/sp/me/player/repeat'], true))));
check('spotify: every control said, with its value', $outs === ['Paused.', 'Resumed on Kitchen speaker.', 'Next song.', 'Previous song.', 'Volume 50%.', 'Volume 100%.', 'Shuffle off.', 'Shuffle on.', 'Repeat: track.', 'Repeat: context.', "Now playing on Alex's phone."]
    && $q[0]['volume_percent'] === '50' && $q[2]['state'] === 'false' && $q[5]['state'] === 'context', json_encode([$outs, $q]));
check('spotify: an unknown control is refused', str_contains($err(fn() => $sp->control('rewind')), 'unknown action rewind'));
$routes(['GET /sp/me/player' => [200, ''], 'GET /sp/me/player/devices' => [200, ['devices' => [$phone]]], 'PUT /sp/me/player' => [204, '']]);
check('spotify: music moved to a device even when nothing plays', $sp->control('transfer', '') === "Now playing on Alex's phone.");
$routes(['GET /sp/me/player' => [200, ''], 'GET /sp/me/player/devices' => [200, ['devices' => []]]]);
check('spotify: moved with no device at all', str_contains($err(fn() => $sp->control('transfer')), 'No Spotify device is available.'));
// now
$routes(['GET /sp/me/player' => [200, ['is_playing' => false, 'shuffle_state' => true, 'repeat_state' => 'track', 'progress_ms' => 61000, 'device' => $kitchen,
    'item' => ['name' => 'Get Lucky', 'uri' => 'spotify:track:t1', 'duration_ms' => 248000, 'artists' => [['name' => 'Daft Punk']], 'album' => ['name' => 'RAM']]]], 'GET /sp/me/player/devices' => [200, ['devices' => []]]]);
$now = $sp->now();
check('spotify: what plays (paused, shuffle, repeat) and no device', $now === "Paused: \"Get Lucky\" by Daft Punk (RAM), 1:01 / 4:08 on Kitchen speaker, volume 40%, shuffle, repeat track. uri spotify:track:t1\nNo device available (Spotify must be open on the phone or a speaker).", $now);
$routes(['GET /sp/me/player' => [200, ['is_playing' => true, 'device' => ['name' => 'Kitchen speaker'], 'item' => ['name' => 'Jingle', 'uri' => 'spotify:track:j']]], 'GET /sp/me/player/devices' => [200, ['devices' => [$kitchen + ['is_active' => true], $phone]]]]);
$now = $sp->now();
check('spotify: what plays (no artist, no volume) and the devices', $now === "Playing: \"Jingle\", 0:00 / 0:00 on Kitchen speaker, volume ?%. uri spotify:track:j\nDevices: Kitchen speaker (Speaker, active), Alex's phone (Smartphone)", $now);
// save
$routes(['GET /sp/search' => [200, ['tracks' => ['items' => []]]]]);
check('spotify: saving a song not found is said', str_contains($err(fn() => $sp->save('nothing at all')), 'Nothing found on Spotify for "nothing at all".'));
$routes(['GET /sp/me/player' => [200, '']]);
check('spotify: saving with nothing playing', $sp->save() === 'Nothing is playing: say which song to save.');
$playing = ['GET /sp/me/player' => [200, ['item' => $track, 'device' => $kitchen]]];
$routes($playing + ['PUT /sp/me/library' => [404, ['error' => ['message' => 'Not found']]], 'PUT /sp/me/tracks' => [200, '']]);
$o = $sp->save();
$tc = array_values(array_filter($calls(), fn($x) => $x['path'] === '/sp/me/tracks'));
check('spotify: liked through the older address when the new one is missing', $o === 'Saved "Get Lucky" by Daft Punk, Pharrell to your liked songs.' && ($tc[0]['q']['ids'] ?? '') === 't1', $o . json_encode($tc));
$routes($playing + ['PUT /sp/me/library' => [500, ['error' => ['message' => 'Server error']]]]);
check('spotify: another failure to like is said', str_contains($err(fn() => $sp->save()), 'Spotify: Server error'));
$routes($playing + ['GET /sp/me/playlists' => [200, ['items' => [$plist, ['id' => 'pl2', 'uri' => 'spotify:playlist:pl2', 'name' => 'Sunday jazz']]]], 'POST /sp/playlists/pl2/items' => [201, ['snapshot_id' => 's']]]);
$o1 = $sp->save('', 'Gym'); $o2 = $sp->save('', 'jazz');
check('spotify: a playlist not found lists yours; found by part of its name', $o1 === 'No playlist of yours is called "Gym". Yours: Running, Sunday jazz.' && $o2 === 'Added "Get Lucky" by Daft Punk, Pharrell to "Sunday jazz".', $o1 . ' | ' . $o2);

// the assistant's Spotify tools
$spt = new \Talixio\Tools($app);
$sp->register($spt);
$album = ['id' => 'al1', 'uri' => 'spotify:album:al1', 'name' => 'Random Access Memories', 'artists' => [['name' => 'Daft Punk']]];
$routes($playing + ['GET /sp/me/player/devices' => [200, ['devices' => [$kitchen + ['is_active' => true]]]], 'GET /sp/search' => [200, ['albums' => ['items' => [null, $album]]]],
    'PUT /sp/me/player/play' => [204, ''], 'PUT /sp/me/player/pause' => [204, ''], 'PUT /sp/me/library' => [200, '']]);
$o1 = $spt->execute('spotify_play', ['query' => 'random access', 'type' => 'album']);
$pc = array_values(array_filter($calls(), fn($x) => $x['path'] === '/sp/me/player/play'));
check('spotify tools: play an album by name', str_contains($o1, 'the album "Random Access Memories" by Daft Punk') && str_contains($pc[0]['body'] ?? '', 'spotify:album:al1'), $o1);
check('spotify tools: pause, what is playing, save', !str_starts_with($spt->execute('spotify_control', ['action' => 'pause']), 'error')
    && str_contains($spt->execute('spotify_now', []), 'Get Lucky') && str_contains($spt->execute('spotify_save', []), 'Saved "Get Lucky"'));
$routes(['GET /sp/me/player' => [200, ''], 'GET /sp/me/player/devices' => [200, ['devices' => [$kitchen]]]]);
check('spotify: nothing playing, the devices still listed', str_starts_with($sp->now(), "Nothing is playing.\nDevices: Kitchen speaker (Speaker)"), $sp->now());
check('spotify: an unknown sign-in state is refused', str_contains($err(fn() => $sp->finish('c', 'not-the-state')), 'expired'));
check('spotify: its callback address sits next to Google\'s', str_ends_with(\Talixio\Spotify::redirectUri('https://x.acme.example'), '/api/spotify/callback'));
$sp->disconnect();
$spt2 = new \Talixio\Tools($app); $before = $spt2->names(); $sp->register($spt2);
check('spotify: disconnected, the tokens are gone and no tool is added', !is_file($spFile) && !$sp->connected() && $spt2->names() === $before);

// ======================= Board =======================
$app = $mkApp(['TALIXIO_TASK_REMINDER' => '18:00']);
$restoreEnv();
$bd = $app->board();
$spA = $app->spaces()->save(['name' => 'Maison', 'code' => 'mai']);
$spB = $app->spaces()->save(['name' => 'Maintenance']);   // same three letters
$spC = $app->spaces()->save(['name' => '123 !!']);          // no letters
$spG = $app->spaces()->save(['name' => 'Genealogy']);       // would be GEN, kept for General
check('board: task codes per space (set, taken, no letters, not GEN)', $bd->prefix((int)$spA['id']) === 'MAI' && $bd->prefix((int)$spB['id']) === 'MA2' && $bd->prefix((int)$spC['id']) === 'TSK'
    && $bd->prefix((int)$spG['id']) === 'GE2' && $bd->prefix(0) === 'GEN' && $bd->prefix(999) === 'GEN', json_encode([$bd->prefix((int)$spB['id']), $bd->prefix((int)$spC['id']), $bd->prefix((int)$spG['id'])]));
check('board: a task code is letters or digits, starting with a letter', str_contains($err(fn() => Board::cleanPrefix('1x')), '2 to 6 letters') && Board::cleanPrefix('ab-1') === 'AB1');
check('board: workloads in hours, minutes or days', Board::hours('1h30') === 1.5 && Board::hours('45m') === 0.75 && Board::hours('2d') === 14.0 && Board::hours('2j') === 14.0
    && Board::hours(3) === 3.0 && Board::hours(-2.5) === 0.0 && Board::hours('1,5') === 1.5 && Board::hours('2 heures') === 2.0 && Board::hours('') === null && str_contains($err(fn() => Board::hours('abc')), 'unclear workload "abc"'));
check('board: workloads written back', Board::fmtHours(null) === '' && Board::fmtHours(14.0) === '2d' && Board::fmtHours(0.5) === '30m' && Board::fmtHours(2.25) === '2.25h' && Board::fmtHours(3.0) === '3h');
$A = (int)$spA['id'];
$bad = fn(array $d) => $err(fn() => $bd->create($d + ['title' => 'x'], $A));
check('board: wrong values refused, each with its reason', str_contains($bad(['title' => '  ']), 'needs a title') && str_contains($bad(['status' => 'later']), 'status is one of')
    && str_contains($bad(['priority' => 0]), 'priority is 1') && str_contains($bad(['priority' => 'P9']), 'priority is 1') && str_contains($bad(['due_date' => '2025-02-30']), 'due must be a date')
    && str_contains($bad(['attendees' => 'ann@@acme.example']), 'Not an email address') && str_contains($bad(['attendees' => implode(',', array_map(fn($i) => "g$i@acme.example", range(1, 51)))]), 'At most 50 guests')
    && str_contains($bad(['space' => 999]), 'no such space') && str_contains($bad(['start_date' => '2026-05-02', 'due_date' => '2026-05-01']), 'The start is after the due date')
    && str_contains($bad(['end_time' => '10:00']), 'An end time needs a start time') && str_contains($bad(['start_time' => '10:00']), 'A time needs a date')
    && str_contains($bad(['due_date' => '2026-05-01', 'start_time' => '10:00', 'end_time' => '09:00']), 'before the start time') && str_contains($bad(['start_time' => '7pm', 'due_date' => '2026-05-01']), 'HH:MM'));
$c1 = $bd->create(['title' => 'Paint the kitchen', 'priority' => 2, 'tags' => 'house, paint', 'due_date' => '2026-11-30'], $A);
$c2 = $bd->create(['title' => 'Buy paint', 'tags' => ['shop'], 'due_date' => '2026-12-05'], $A);
$c3 = $bd->create(['title' => 'Done thing', 'status' => 'done'], $A);
$c4 = $bd->create(['title' => 'Doing thing', 'status' => 'doing'], $A);
check('board: created with the codes of its space; done at once is done', $c1['code'] === 'MAI-1' && $c2['code'] === 'MAI-2' && $c3['done_at'] !== null);
$u = $bd->update($c1['id'], ['tags' => '', 'estimate_hours' => '2d', 'in_calendar' => true, 'links' => [['label' => 'Quote', 'type' => 'file', 'ref' => '12'], 'junk'], 'location' => 'Home', 'body' => "- [x] tape\n- [ ] paint"]);
$u = $bd->update($c1['id'], ['estimate_hours' => null, 'in_calendar' => 'false', 'tags' => 'house']);
$h = array_column($bd->history($c1['id']), 'what');
check('board: changes described (tags none, workload set then removed, calendar, links, place)', str_contains($h[1], 'tags: none') && str_contains($h[1], 'workload 2d') && str_contains($h[1], 'shown in the calendar')
    && str_contains($h[1], 'links changed') && str_contains($h[1], 'location: Home') && str_contains($h[1], 'description edited') && str_contains($h[0], 'workload removed')
    && str_contains($h[0], 'hidden from the calendar') && str_contains($h[0], 'tags: house') && $u['links'] === [['label' => 'Quote', 'type' => 'file', 'ref' => '12']], json_encode($h));
$u = $bd->update($c1['id'], ['due_date' => '2026-11-30']);
check('board: nothing changed, nothing written', $u['version'] === $bd->get($c1['id'])['version'] && count($bd->history($c1['id'])) === 3);
check('board: a change on a card changed meanwhile is refused', $err(fn() => $bd->update($c1['id'], ['title' => 'x'], 'you', 1)) !== '' && str_contains($err(fn() => $bd->update($c1['id'], ['title' => 'x'], 'you', 1)), 'changed meanwhile'));
check('board: the start after the due date (kept one) is refused', str_contains($err(fn() => $bd->update($c1['id'], ['start_date' => '2026-12-31'])), 'start is after'));
$m = $bd->update($c2['id'], ['space' => (int)$spB['id']]);
check('board: moved to another space, a new code, the old one still found', $m['code'] === 'MA2-1' && $bd->find('MAI-2')['id'] === $c2['id'] && $bd->find('ma2-1')['id'] === $c2['id']
    && $bd->find('#' . $c2['id'])['id'] === $c2['id'] && $bd->find((string)$c2['id'])['id'] === $c2['id'] && $bd->find('ZZZ-9') === null && str_contains($bd->history($c2['id'])[0]['what'], 'moved to space Maintenance'));
check('board: an unknown task is said', str_contains($err(fn() => $bd->need('ZZZ-1')), 'no task ZZZ-1') && str_contains($err(fn() => $bd->update(99999, ['title' => 'x'])), 'no task 99999'));
// dependencies
$d1 = $bd->create(['title' => 'Step one', 'due_date' => '2026-12-10'], $A);
$d2 = $bd->create(['title' => 'Step two', 'due_date' => '2026-12-01', 'depends_on' => [$d1['code']]], $A);
check('board: a task cannot wait for itself, nor make a loop', str_contains($err(fn() => $bd->update($d2['id'], ['add_depends_on' => [$d2['code']]])), 'cannot depend on itself')
    && str_contains($err(fn() => $bd->update($d1['id'], ['depends_on' => [$d2['code']]])), 'That would make a loop: ' . $d1['code'] . ' → ' . $d2['code'] . ' → ' . $d1['code']));
$w = array_column($bd->warnings($bd->get($d2['id'])), 'kind');
check('board: waiting on a task due later is warned', $w === ['waiting', 'dates'], json_encode($w));
$od = $bd->create(['title' => 'Late', 'due_date' => '2020-01-01', 'depends_on' => [$d1['id']]], $A);
$bd->delete($d1['id']);
$w = $bd->warnings($bd->get($od['id']));
check('board: overdue, and waiting for a task in the trash', array_column($w, 'kind') === ['overdue', 'deleted'] && str_contains($w[1]['text'], 'which is in the trash'), json_encode($w));
check('board: a task in the trash cannot be changed', str_contains($err(fn() => $bd->update($d1['id'], ['title' => 'x'])), 'is in the trash'));
$bd->update($od['id'], ['remove_depends_on' => [$d1['code']], 'add_depends_on' => [$d2['code']]]);
check('board: dependencies added and removed', $bd->get($od['id'])['depends_on'] === [$d2['id']] && str_contains($bd->history($od['id'])[0]['what'], 'dependencies: ' . $d2['code']));
$bd->update($od['id'], ['depends_on' => []]);
check('board: dependencies emptied', $bd->get($od['id'])['depends_on'] === [] && str_contains($bd->history($od['id'])[0]['what'], 'dependencies: none'));
// order
$e1 = $bd->create(['title' => 'E1'], $A); $e2 = $bd->create(['title' => 'E2'], $A); $e3 = $bd->create(['title' => 'E3', 'status' => 'doing'], $A);
$ids = $bd->reorder([$e2['code'], $e3['code'], '', $e1['id']]);
$todo = array_column($bd->list(['space' => $A, 'status' => 'todo']), 'id');
check('board: reordered, each in its column', $ids === [$e2['id'], $e3['id'], $e1['id']] && array_slice($todo, 0, 2) === [$e2['id'], $e1['id']]
    && array_column($bd->list(['space' => $A, 'status' => 'doing']), 'id')[0] === $e3['id'], json_encode($todo));
$bd->setGlobalOrder([$e1['code']]);
$bd->clearGlobalRank($e1['id']);
check('board: removed from the overall order', $bd->get($e1['id'])['global_rank'] === null && $bd->history($e1['id'])[0]['what'] === 'removed from the overall order');
// time
$t = $bd->addTime($e1['id'], '1h30', '2026-10-01', 'first go');
$en = $bd->timeEntries($e1['id']);
check('board: time spent, listed', $t['spent_hours'] === 1.5 && count($en) === 1 && $en[0]['note'] === 'first go' && $bd->history($e1['id'])[0]['what'] === '1.5h spent on 2026-10-01: first go');
$bd->deleteTime((int)$en[0]['id']);
check('board: time entry removed; no time is refused', $bd->get($e1['id'])['spent_hours'] === 0.0 && $bd->timeEntries($e1['id']) === [] && str_contains($err(fn() => $bd->addTime($e1['id'], 0)), 'How much time?')
    && str_contains($err(fn() => $bd->addTime($e1['id'], '1h', '2026-13-01')), 'day must be a date'));
// lists
$bd->update($e2['id'], ['tags' => 'house, urgent']);
check('board: tags counted, per space', $bd->tags($A)['house'] === 2 && !isset($bd->tags($A)['shop']) && $bd->tags()['shop'] === 1 && $bd->tags((int)$spB['id']) === ['shop' => 1]);
check('board: lists by tag, search, open, trash, overall order', array_column($bd->list(['tag' => 'urgent']), 'id') === [$e2['id']] && array_column($bd->list(['q' => 'kitchen']), 'id') === [$c1['id']]
    && !in_array($c3['id'], array_column($bd->list(['open' => true]), 'id'), true) && array_column($bd->list(['trash' => true]), 'id') === [$d1['id']]
    && count($bd->list(['global' => true, 'space' => ''])) === count($bd->list()));
$rec = $bd->recent((int)$spB['id']);
check('board: recent changes of one space', count($rec) >= 1 && array_unique(array_column($rec, 'space')) === [(int)$spB['id']] && count($bd->recent(null, 3)) === 3);
check('board: dates of a space', array_column($bd->inRange('2026-11-01', '2026-12-31', (int)$spB['id']), 'id') === [$c2['id']] && count($bd->inRange('2026-11-01', '2026-12-31')) >= 3);
// lines and full description
$full = $bd->create(['title' => 'Everything', 'start_date' => '2026-11-02', 'due_date' => '2026-11-03', 'start_time' => '09:00', 'end_time' => '10:00', 'estimate_hours' => '3h', 'tags' => 'a,b',
    'in_calendar' => true, 'location' => 'Room 1', 'attendees' => 'ann@acme.example', 'body' => "Notes\n- [x] one\n- [ ] two", 'links' => [['label' => 'Spec', 'type' => 'url', 'ref' => 'https://acme.example/spec']],
    'depends_on' => [$e1['code']]], $A);
$bd->addTime($full['id'], 1);
$bd->setGlobalOrder([$full['code']]);
$blk = $bd->create(['title' => 'After everything', 'depends_on' => [$full['id']]], $A);
$desc = $bd->describeCard($bd->get($full['id']));
check('board: a card described with every field', str_contains($desc, 'start 2026-11-02, due 2026-11-03, from 09:00 to 10:00, workload 3h, spent 1h, checklist 1/2, #a #b, waits for ' . $e1['code'] . ', space Maison, overall #1, in calendar, at Room 1, guests ann@acme.example (invitation not sent yet)')
    && str_contains($desc, "\nNotes\n") && str_contains($desc, "Blocks: {$blk['code']} After everything") && str_contains($desc, 'Links: Spec (url https://acme.example/spec)') && str_contains($desc, '⚠ Waits for'), $desc);
$bd->update($e1['id'], ['status' => 'done']);
$ics = $bd->ics();
check('board: calendar feed: a timed task with its place; a done one is ticked', str_contains($ics, 'LOCATION:Room 1') && str_contains($ics, 'DTSTART:' . gmdate('Ymd\THis\Z', strtotime('2026-11-02 09:00'))) && str_contains($ics, 'TRANSP:OPAQUE'));
$bd->update($e1['id'], ['in_calendar' => true, 'due_date' => '2026-11-01']);
check('board: a done task in the feed is ticked', str_contains($bd->ics(), 'SUMMARY:✓ ' . $e1['code']));
$tok = $bd->icsToken();
check('board: the calendar link is kept, and can be renewed', $bd->icsToken() === $tok && ($t2 = $bd->icsToken(true)) !== $tok && $bd->icsToken() === $t2 && strlen($t2) === 40);
// syncing to the calendars
$app->cfg->tasksGoogle = false;
check('board: calendar sync off', $bd->syncGoogle() === 0);
$app->cfg->tasksGoogle = true;
foreach (['googleClientId' => 'gid', 'googleClientSecret' => 'gs', 'googleTokenUrl' => "$F/gtok", 'googleApiBase' => "$F/gcal", 'microsoftClientId' => 'mid', 'microsoftClientSecret' => 'ms', 'microsoftLoginBase' => "$F/ms", 'microsoftApiBase' => "$F/graph"] as $k => $v) $app->cfg->$k = $v;
file_put_contents($app->cfg->dataDir . '/google.json', json_encode(['refresh_token' => 'gr']));
file_put_contents($app->cfg->dataDir . '/microsoft.json', json_encode(['refresh_token' => 'mr']));
$routes(['POST /gtok' => [400, ['error' => 'invalid_grant']], 'POST /ms/common/oauth2/v2.0/token' => [400, ['error' => 'invalid_grant']]]);
$app->cfg->googleClientId = 'gid';
$n0 = $bd->syncGoogle();
check('board: a calendar that fails stops the sync until next time (Google and Outlook)', $n0 === 0 && $app->store->q('SELECT COUNT(*) FROM cards WHERE gcal_id IS NOT NULL OR ocal_id IS NOT NULL')->fetchColumn() == 0
    && count(array_filter($calls(), fn($x) => in_array($x['path'], ['/gtok', '/ms/common/oauth2/v2.0/token'], true))) === 2, json_encode($calls()));
foreach (['googleClientId', 'microsoftClientId'] as $k) $app->cfg->$k = '';
// reminders: due tomorrow, per space
$now = strtotime('2026-11-29 18:30');
$app->store->q("UPDATE cards SET due_date = '2026-12-31' WHERE due_date = '2026-11-30'");
$r1 = $bd->create(['title' => 'Call the plumber', 'status' => 'doing', 'due_date' => '2026-11-30', 'depends_on' => [$od['code']]], $A);
$r2 = $bd->create(['title' => 'Pay the bill', 'due_date' => '2026-11-30'], (int)$spB['id']);
$r3 = $bd->create(['title' => 'Water plants', 'due_date' => '2026-11-30'], (int)$spB['id']);
check('board: no reminder before the time', $bd->remind(strtotime('2026-11-29 17:00')) === 0);
$sent = $bd->remind($now);
check('board: tasks due tomorrow told once a day, each space on its own', $sent === 3 && $bd->remind($now + 60) === 0);
$app->cfg->taskReminder = '';
check('board: no reminder when turned off', $bd->remind($now + 86400) === 0);
// invitations from the assistant's address: all-day over several days, without a place nor a name
$sport = free_port();
foreach (['smtpHost' => '127.0.0.1', 'smtpSecurity' => 'none', 'smtpPort' => $sport, 'emailFrom' => 'bot@acme.example', 'emailTo' => 'alex@acme.example', 'mailboxSmtpHost' => ''] as $k => $v) $app->cfg->$k = $v;
$inv = $bd->create(['title' => 'Offsite', 'start_date' => '2026-12-07', 'due_date' => '2026-12-09', 'attendees' => 'ann@acme.example'], $A);
$sp = proc_open([PHP_BINARY, "$root/tests/fakes/smtp.php", (string)$sport, "$d/inv.log"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $spp);
wait_port($sport);
$ir = $bd->invite($inv['id']); proc_close($sp);
$il = (string)@file_get_contents("$d/inv.log");
$ical = implode('', array_map(fn($b) => base64_decode(preg_replace('/\s+/', '', $b)), preg_match_all("/Content-Type: text\/calendar[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n(.+?)\r\n--/s", $il, $mm) ? $mm[1] : []));
check('board: an all-day invitation over several days, organizer without a name, no place', $ir === "{$inv['code']}: invitation sent to ann@acme.example" && str_contains($ical, 'ORGANIZER:mailto:alex@acme.example')
    && str_contains($ical, 'DTSTART;VALUE=DATE:20261207') && str_contains($ical, 'DTEND;VALUE=DATE:20261210') && !str_contains($ical, 'LOCATION') && str_contains($il, 'Reply-To: alex@acme.example'), $il);

// ======================= Cli (as a separate process) =======================
$cliDir = "$d/cli"; @mkdir($cliDir);
$cli = function (array $args, array $env = [], ?string $stdin = null, string $dir = '') use ($root, $cliDir, $base, $fenv): array {
    $env = $env + ['TALIXIO_DATA_DIR' => $dir ?: $cliDir] + $base + $fenv + ['HOME' => getenv('HOME') ?: '/tmp'];
    $p = proc_open(array_merge([PHP_BINARY, "$root/bin/talixio"], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, null, $env);
    if ($stdin !== null) fwrite($pp[0], $stdin);
    fclose($pp[0]);
    $out = stream_get_contents($pp[1]); $er = stream_get_contents($pp[2]);
    fclose($pp[1]); fclose($pp[2]);
    return [proc_close($p), $out, $er];
};
[$c, $o] = $cli(['version']);
check('cli: version prints the name and returns 0', $c === 0 && str_starts_with($o, 'Talixio '), $o);
[$c, , $e] = $cli(['nonsense']);
check('cli: an unknown command prints the usage and returns 2', $c === 2 && str_contains($e, 'usage: talixio'), $e);
[$c, , $e] = $cli(['transfer-pull', 'https://old.acme.example']);
check('cli: transfer-pull without a code prints its usage', $c === 2 && str_contains($e, 'usage: talixio transfer-pull'), $e);
[$c, , $e] = $cli(['transfer-pull', 'http://127.0.0.1:1', 'not-a-code', '--url', 'https://new.acme.example', '--final']);
check('cli: transfer-pull with a bad code ends with an error', $c === 1 && str_starts_with($e, 'error: '), $e);
[$c, , $e] = $cli(['job', '0']);
check('cli: job 0 does not exist and ends cleanly', $c === 0 || str_starts_with($e, 'error: '), "$c $e");
[$c] = $cli(['connector-install']);
check('cli: connector-install with nothing running does nothing', $c === 0);
[$c] = $cli(['index']);
check('cli: index with nothing waiting does nothing', $c === 0);
$routes(['POST /ollama/api/pull' => [200, ['status' => 'success']]]);
[$c] = $cli(['embeddings-pull', 'nomic-embed-text'], ['TALIXIO_OLLAMA_URL' => "$F/ollama"]);
check('cli: embeddings-pull asks Ollama for the model', $c === 0 && ($calls()[0]['path'] ?? '') === '/ollama/api/pull' && str_contains($calls()[0]['body'], 'nomic-embed-text'));
[$c, $o] = $cli(['mcp'], ['TALIXIO_SPACE' => '0', 'TALIXIO_IN_WORKER' => '1', 'TALIXIO_JOB' => '0'],
    json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]) . "\nnot json\n" . json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']) . "\n");
$lines = array_values(array_filter(explode("\n", $o)));
check('cli: mcp answers JSON-RPC lines and skips the rest', count($lines) === 2 && json_decode($lines[0], true)['result']['serverInfo']['name'] === 'talixio'
    && count(json_decode($lines[1], true)['result']['tools']) > 5, $o);
[$c, $o] = $cli(['chat'], [], "hello there\n\n");
check('cli: chat answers each line with the echo provider', $c === 0 && str_contains($o, '(provider echo)') && substr_count($o, '> ') === 3, $o);
// boot: first password, then resets applied once
$bdir = "$d/cliboot"; @mkdir($bdir);
[$c] = $cli(['boot'], ['TALIXIO_INIT_PASSWORD_HASH' => '$2y$hash1'], null, $bdir);
$sj = json_decode((string)file_get_contents("$bdir/settings.json"), true);
check('cli: boot sets the first password hash once', $c === 0 && $sj['TALIXIO_WEB_PASSWORD_HASH'] === '$2y$hash1' && $sj['TALIXIO_INIT_DONE'] === '1');
[$c] = $cli(['boot'], ['TALIXIO_INIT_PASSWORD_HASH' => '$2y$hash2'], null, $bdir);
$sj = json_decode((string)file_get_contents("$bdir/settings.json"), true);
check('cli: boot does not set the first password again', $sj['TALIXIO_WEB_PASSWORD_HASH'] === '$2y$hash1');
[$c, $o] = $cli(['boot'], ['TALIXIO_RESET' => 'n1:password:$2y$hash3'], null, $bdir);
$sj = json_decode((string)file_get_contents("$bdir/settings.json"), true);
check('cli: boot applies a password reset', str_contains($o, 'password reset') && $sj['TALIXIO_WEB_PASSWORD_HASH'] === '$2y$hash3' && $sj['TALIXIO_RESET_DONE'] === 'n1', $o);
$sj['TALIXIO_TOTP_SECRET'] = 'ABCDEFGH'; file_put_contents("$bdir/settings.json", json_encode($sj));
[$c, $o] = $cli(['boot'], ['TALIXIO_RESET' => 'n1:totp'], null, $bdir);
check('cli: boot does not apply the same reset twice', $o === '' && json_decode((string)file_get_contents("$bdir/settings.json"), true)['TALIXIO_TOTP_SECRET'] === 'ABCDEFGH', $o);
[$c, $o] = $cli(['boot'], ['TALIXIO_RESET' => 'n2:totp'], null, $bdir);
$sj = json_decode((string)file_get_contents("$bdir/settings.json"), true);
check('cli: boot turns two-step sign-in off on a new reset', str_contains($o, 'two-step sign-in turned off') && !isset($sj['TALIXIO_TOTP_SECRET']) && $sj['TALIXIO_RESET_DONE'] === 'n2', $o);
[$c, $o] = $cli(['drain']);
check('cli: drain prints the running jobs and leaves the drain file', $c === 0 && trim($o) === '0' && is_file(\Talixio\Jobs::drainFile($cliDir)), $o);
[$c] = $cli(['drain', 'off']);
clearstatcache();
check('cli: drain off removes the drain file', $c === 0 && !is_file(\Talixio\Jobs::drainFile($cliDir)));
[$c, $o, $e] = $cli(['backup']);
check('cli: backup prints what it did', $c === 0 && trim($o) !== '', "$c $o $e");
[$c, $o] = $cli(['doctor']);
check('cli: doctor prints its checks and returns 1 when one fails (no password yet)', $c === 1 && str_contains($o, 'Talixio doctor, version') && str_contains($o, "\n ok  PHP ") && str_contains($o, "\n !!  app password set"), $o);
[$c, , $e] = $cli(['password'], [], "short\n");
check('cli: a password under 12 characters is refused', $c === 1 && str_contains($e, 'at least 12'), $e);
[$c, $o] = $cli(['password'], [], "a-long-enough-pass\n");
check('cli: a long password is set', $c === 0 && str_contains($o, 'New app password: a-long-enough-pass'), $o);
[$c, $o] = $cli(['password'], [], "\n");
check('cli: an empty password makes one up', $c === 0 && preg_match('/New app password: \S{20}\n/', $o) === 1, $o);
[$c, $o] = $cli(['totp']);
check('cli: totp says whether two-step sign-in is on', $c === 0 && str_contains($o, 'Two-step sign-in is off.'), $o);
[$c, $o] = $cli(['totp', 'off']);
check('cli: totp off turns it off', $c === 0 && str_contains($o, 'Two-step sign-in is off. Turn it on again'), $o);
[$c, , $e] = $cli(['signal', 'register']);
check('cli: signal without a number is refused', $c === 1 && str_contains($e, 'Settings → Signal'), $e);
$sent = "$d/signal-sent.txt"; @unlink($sent);
[$c] = $cli(['signal', 'send', '-m', 'hi', '+15550001'], ['TALIXIO_SIGNAL_NUMBER' => '+15550000', 'TALIXIO_SIGNAL_CLI' => "$root/tests/fakes/signal-cli", 'TALIXIO_FAKE_SIGNAL_SENT' => $sent]);
check('cli: signal runs signal-cli for the assistant\'s number', $c === 0 && str_contains((string)@file_get_contents($sent), '+15550001') && is_dir("$cliDir/signal"), (string)@file_get_contents($sent));
[$c] = $cli(['signal', 'frobnicate'], ['TALIXIO_SIGNAL_NUMBER' => '+15550000', 'TALIXIO_SIGNAL_CLI' => "$root/tests/fakes/signal-cli"]);
check('cli: signal passes on signal-cli\'s exit code', $c === 1);

// ======================= Worker =======================
$closed = free_port(); // nothing listens there
$wa = $mkApp(['TALIXIO_HEARTBEAT_MINUTES' => '1', 'TALIXIO_SIGNAL_NUMBER' => '+15550000', 'TALIXIO_SIGNAL_ALLOWED' => '+15550001', 'TALIXIO_SIGNAL_CLI' => "$d/no-such-signal-cli",
    'TALIXIO_IMAP_HOST' => '127.0.0.1', 'TALIXIO_IMAP_PORT' => (string)$closed, 'TALIXIO_IMAP_SECURITY' => 'none', 'TALIXIO_EMAIL_ALLOWED' => 'alex@acme.example', 'TALIXIO_SMTP_HOST' => '127.0.0.1', 'TALIXIO_SMTP_PORT' => (string)$closed, 'TALIXIO_EMAIL_FROM' => 'ada@acme.example',
    'TALIXIO_MAILBOX_IMAP_HOST' => '127.0.0.1', 'TALIXIO_MAILBOX_IMAP_PORT' => (string)$closed, 'TALIXIO_MAILBOX_IMAP_SECURITY' => 'none']);
$restoreEnv();
$fm = new FakeModel();
$fm->script = fn() => 'HEARTBEAT_OK: nothing to do';
$wa->providerOverride = $fm;
$ws = $wa->store;
$ws->set('backup_last', (string)time());
$day = intdiv(time(), 86400) * 86400; // a whole day: the daily and hourly housekeeping runs too
$t1 = $ws->addTask('Rerun report', 'x', null, $day - 5, 'standard', 0, 99999);
$wa->ws->write('HEARTBEAT.md', "- check the calendar\n");
$wa->mcp()->saveRaw(json_encode(['mcpServers' => ['remote' => ['url' => "http://127.0.0.1:$closed/mcp"]]]));
$wk = new Worker($wa);
App::$inWorker = true;
$wk->tick($day);
App::$inWorker = false;
$task = $ws->q('SELECT * FROM tasks WHERE id = ?', [$t1])->fetch();
check('worker: a scheduled job that cannot start is turned off, not retried', (int)$task['enabled'] === 0 && (int)$task['last_run'] === $day);
check('worker: the heartbeat asks the model with HEARTBEAT.md', (int)$ws->get('heartbeat_last') === $day && $fm->calls && str_contains(\Talixio\Content::text(end($fm->calls[0]['messages'])['content']), 'check the calendar'));
check('worker: a signal-cli that is missing is shown as a channel error', $ws->get('channel_error_signal') !== '', $ws->get('channel_error_signal'));
check('worker: an IMAP server that does not answer is shown as a channel error', $ws->get('channel_error_email') !== '', $ws->get('channel_error_email'));
check('worker: the connectors\' tool lists are refreshed', ($wa->mcp()->cache()['remote']['error'] ?? null) !== null);
// a second tick soon after: nothing is due again; signal now works, so the error is cleared
$wa->cfg->signalCli = "$root/tests/fakes/signal-cli";
$calls0 = count($fm->calls);
$wk->tick($day + 7);
check('worker: a working signal-cli clears the channel error', $ws->get('channel_error_signal') === '');
check('worker: the heartbeat waits for its interval', count($fm->calls) === $calls0);
// a heartbeat that has something to say is sent; one that stops with Abort is only logged
$fm->script = fn() => throw new \Talixio\Abort('stopped by the user');
$wk->tick($day + 120);
check('worker: an Abort in the heartbeat is only logged', (int)$ws->get('heartbeat_last') === $day + 120);
// paused by a transfer: due tasks are left alone
$ws->set('paused', json_encode(['reason' => 'moving', 'url' => 'https://new.acme.example']));
$t2 = $ws->addTask('Paused one', 'x', '0 9 * * *', $day + 100, 'standard');
$wk->tick($day + 300);
check('worker: while paused, due tasks wait', (int)$ws->q('SELECT next_run FROM tasks WHERE id = ?', [$t2])->fetchColumn() === $day + 100);
$ws->set('paused', '');
// a routine with a schedule: run by the model, then moved to its next time; a heartbeat with news is sent on
$t3 = $ws->addTask('Weekly digest', 'Sum up the week for Alex', '0 9 * * 1', $day + 500, 'standard');
$fm->script = fn() => 'Alex has 3 open tasks at acme.example';
$c0 = count($fm->calls);
$wk->tick($day + 600);
$t3r = $ws->q('SELECT * FROM tasks WHERE id = ?', [$t3])->fetch();
check('worker: a scheduled routine runs and moves to its next time', (int)$t3r['enabled'] === 1 && (int)$t3r['last_run'] === $day + 600 && (int)$t3r['next_run'] === \Talixio\Cron::next('0 9 * * 1', $day + 600) && count($fm->calls) >= $c0 + 2);

// run(): the loop as its own process, ended by a settings change and by a transfer pause
$wdir = "$d/workerrun"; @mkdir($wdir);
$wenv = ['TALIXIO_DATA_DIR' => $wdir] + $base + $fenv + ['HOME' => getenv('HOME') ?: '/tmp'];
$setEnv(['TALIXIO_DATA_DIR' => $wdir] + $base);
$wra = App::fromEnv();
$restoreEnv();
$wra->store->q("INSERT INTO files(name, mime, kind, size, path, status, created) VALUES('old.pdf', 'application/pdf', 'pdf', 10, 'gone/old.pdf', 'ready', ?)", [time()]);
touch(\Talixio\Jobs::drainFile($wdir));
$runWorker = function (callable $poke, array $extra = []) use ($root, $wenv, $wra): array {
    $wra->store->set('worker_seen', '');
    $p = proc_open([PHP_BINARY, "$root/bin/talixio", 'worker'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, null, $extra + $wenv);
    for ($i = 0; $i < 100 && (string)$wra->store->get('worker_seen') === ''; $i++) usleep(100000);
    $poke();
    for ($i = 0; $i < 100 && proc_get_status($p)['running']; $i++) usleep(100000);
    $running = proc_get_status($p)['running'];
    if ($running) proc_terminate($p);
    $err = stream_get_contents($pp[2]); fclose($pp[1]); fclose($pp[2]);
    $code = proc_close($p);
    return [$running ? -1 : $code, $err];
};
[$wc, $we] = $runWorker(function () use ($wdir) { sleep(1); file_put_contents("$wdir/settings.json", json_encode(['TALIXIO_NAME' => 'Ada'])); });
check('worker: run() starts, removes the drain file and stops when the settings change', $wc === 0 && str_contains($we, 'worker started') && str_contains($we, 'settings changed')
    && !is_file(\Talixio\Jobs::drainFile($wdir)) && $wra->store->get('pages_indexed') === '1' && $wra->store->get('briefing_tasks') === '1', $we);
[$wc, $we] = $runWorker(fn() => $wra->store->set('paused', json_encode(['reason' => 'test copy', 'url' => 'https://new.acme.example'])));
check('worker: run() stops when a transfer pauses it', $wc === 0 && str_contains($we, 'transfer pause changed'), $we);
[$wc, $we] = $runWorker(fn() => $wra->store->set('paused', ''));
check('worker: a paused worker says so and restarts when the pause ends', $wc === 0 && str_contains($we, 'paused by a transfer (test copy') && str_contains($we, 'transfer pause changed'), $we);
// started with every channel set (paused, so nothing is reached): the start line says which are on
$wra->store->set('paused', json_encode(['reason' => 'test copy', 'url' => 'https://new.acme.example']));
$wchan = ['TALIXIO_TELEGRAM_TOKEN' => '123:abc', 'TALIXIO_SMTP_HOST' => '127.0.0.1', 'TALIXIO_SMTP_PORT' => (string)$closed, 'TALIXIO_EMAIL_FROM' => 'ada@acme.example', 'TALIXIO_EMAIL_ALLOWED' => 'alex@acme.example',
    'TALIXIO_SIGNAL_NUMBER' => '+15550000', 'TALIXIO_SIGNAL_ALLOWED' => '+15550001', 'TALIXIO_SIGNAL_CLI' => "$root/tests/fakes/signal-cli", 'TALIXIO_NTFY_URL' => "$F/ntfy"];
[$wc, $we] = $runWorker(fn() => $wra->store->set('paused', ''), $wchan);
check('worker: the start line names the channels that are on', $wc === 0 && str_contains($we, 'telegram=on email=out signal=on ntfy=on'), $we);

// ======================= Config =======================
$cfgClean = array_fill_keys(['TALIXIO_PROVIDER', 'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'OPENAI_BASE_URL', 'GEMINI_API_KEY', 'GEMINI_BASE_URL', 'TALIXIO_SOVEREIGN', 'TALIXIO_SOVEREIGN_KEY',
    'TALIXIO_SOVEREIGN_URL', 'TALIXIO_SOVEREIGN_ONLY', 'TALIXIO_TRANSCRIBE', 'TALIXIO_TRANSCRIBE_MODEL', 'TALIXIO_EMBEDDINGS', 'TALIXIO_EMBEDDINGS_MODEL', 'TALIXIO_SEARCH', 'BRAVE_API_KEY',
    'TAVILY_API_KEY', 'SEARXNG_URL', 'VOYAGE_API_KEY', 'TALIXIO_TOOLS', 'TALIXIO_ESCALATE', 'TALIXIO_TASK_REMINDER', 'TALIXIO_AUTO_FILE', 'TALIXIO_LANG', 'TALIXIO_MODEL', 'TALIXIO_MODEL_DEEP',
    'TALIXIO_WHISPER_BIN', 'TALIXIO_WHISPER_MODEL', 'CLAUDE_CODE_OAUTH_TOKEN', 'TALIXIO_CLAUDE_BIN', 'TALIXIO_BASE_PATH'], null);
$cfgOf = function (array $env) use ($d, $setEnv, $restoreEnv, $cfgClean): Config {
    $setEnv($env + ['TALIXIO_DATA_DIR' => "$d/cfg"] + $cfgClean);
    try { return Config::fromEnv(); } finally { $restoreEnv(); }
};
$c = $cfgOf(['TALIXIO_PROVIDER' => 'openai', 'OPENAI_API_KEY' => 'sk-test']);
check('config: openai picks GPT models, OpenAI transcription and embeddings', $c->tiers['fast']->model === 'gpt-4.1-mini' && $c->tiers['deep']->model === 'gpt-4.1'
    && $c->transcribe === 'openai' && $c->transcribeModel === 'gpt-4o-transcribe' && $c->embeddings === 'openai' && $c->embeddingsModel === 'text-embedding-3-small' && $c->search === 'off'
    && $c->tiers['deep']->reasoningEffort === '' && $c->modelProblem() === null && !$c->localModels());
$c = $cfgOf(['TALIXIO_PROVIDER' => 'openai', 'OPENAI_BASE_URL' => 'http://127.0.0.1:8080/v1']);
check('config: a local OpenAI-compatible server needs no key and costs nothing', $c->modelProblem() === null && $c->localModels() && $c->embeddings === 'off' && $c->transcribe === 'off');
$c = $cfgOf(['TALIXIO_PROVIDER' => 'openai']);
check('config: openai without a key says so', $c->modelProblem() === 'no-key');
$c = $cfgOf(['TALIXIO_PROVIDER' => 'sovereign', 'TALIXIO_SOVEREIGN' => 'atlantis', 'TALIXIO_TOOLS' => 'weird', 'TALIXIO_ESCALATE' => 'sometimes']);
check('config: an unknown European provider falls back to OVHcloud, unknown modes to auto', $c->sovereign === 'ovhcloud' && $c->tools === 'auto' && $c->escalate === 'auto'
    && $c->transcribe === 'sovereign' && $c->transcribeModel === 'whisper-large-v3' && $c->embeddings === 'sovereign' && $c->embeddingsModel === 'bge-m3' && $c->modelProblem() === null);
$c->tiers['deep']->model = 'big'; $c->tiers['standard']->model = 'small';
check('config: a European provider moves up to the deep model on its own', $c->escalates());
$c->escalate = 'off';
check('config: escalation can be turned off', !$c->escalates());
$c = $cfgOf(['TALIXIO_PROVIDER' => 'sovereign', 'TALIXIO_SOVEREIGN' => 'mistral']);
check('config: Mistral without a key cannot answer', $c->modelProblem() === 'no-key' && !$c->sovereignReady() && $c->transcribe === 'off');
$c = $cfgOf(['TALIXIO_SOVEREIGN_ONLY' => '1', 'SEARXNG_URL' => 'https://search.acme.example', 'ANTHROPIC_API_KEY' => 'k', 'TALIXIO_SOVEREIGN' => 'mistral', 'TALIXIO_TRANSCRIBE' => 'auto', 'TALIXIO_EMBEDDINGS' => 'auto']);
check('config: Europe only without a ready provider keeps searches on SearXNG and the rest off', $c->search === 'searxng' && $c->transcribe === 'off' && $c->embeddings === 'off');
$c = $cfgOf(['TALIXIO_SOVEREIGN_ONLY' => '1', 'TALIXIO_TRANSCRIBE' => 'auto', 'TALIXIO_EMBEDDINGS' => 'auto']);
check('config: Europe only with OVHcloud (no key needed) uses it for everything, search off', $c->transcribe === 'sovereign' && $c->embeddings === 'sovereign' && $c->search === 'off');
$c = $cfgOf(['TALIXIO_PROVIDER' => 'gemini', 'GEMINI_API_KEY' => 'g', 'GEMINI_BASE_URL' => 'http://127.0.0.1:1/v1beta/openai', 'TALIXIO_EMBEDDINGS' => 'auto', 'TALIXIO_EMBEDDINGS_MODEL' => 'nomic:latest']);
check('config: gemini through its OpenAI endpoint; an Ollama model name is dropped', $c->openaiBaseUrl === 'http://127.0.0.1:1/v1beta/openai' && $c->transcribe === 'gemini'
    && $c->embeddings === 'gemini' && $c->embeddingsModel === 'gemini-embedding-001' && $c->search === 'gemini' && $c->modelProblem() === null);
$c = $cfgOf(['TALIXIO_PROVIDER' => 'gemini']);
check('config: gemini without a key says so', $c->modelProblem() === 'no-key');
$c = $cfgOf(['VOYAGE_API_KEY' => 'v', 'BRAVE_API_KEY' => 'b', 'TALIXIO_EMBEDDINGS' => 'auto', 'TALIXIO_TASK_REMINDER' => '25:00', 'TALIXIO_AUTO_FILE' => 'sometimes', 'TALIXIO_LANG' => 'klingon']);
check('config: voyage, brave; a bad reminder hour, filing mode and language are dropped', $c->embeddings === 'voyage' && $c->embeddingsModel === 'voyage-3.5-lite' && $c->search === 'brave'
    && $c->taskReminder === '' && $c->autoFile === 'suggest' && $c->lang === 'auto' && $c->modelProblem() === 'no-key');
$c = $cfgOf(['TAVILY_API_KEY' => 't', 'TALIXIO_EMBEDDINGS' => 'ollama']);
check('config: tavily search; Ollama with its own model and a higher bar for qwen3', $c->search === 'tavily' && $c->embeddingsModel === 'qwen3-embedding:0.6b' && abs($c->recallMinSim - 0.45) < 1e-9);
$c = $cfgOf(['SEARXNG_URL' => 'https://search.acme.example', 'TALIXIO_PROVIDER' => 'claude-code', 'TALIXIO_CLAUDE_BIN' => "$d/no-claude"]);
check('config: SearXNG search; Claude Code not installed', $c->search === 'searxng' && $c->tiers['standard']->model === 'sonnet' && $c->modelProblem() === 'no-claude-code');
$c = $cfgOf(['TALIXIO_PROVIDER' => 'claude-code', 'TALIXIO_CLAUDE_BIN' => PHP_BINARY, 'CLAUDE_CODE_OAUTH_TOKEN' => 'tok']);
$setEnv(['CLAUDE_CODE_OAUTH_TOKEN' => 'tok']); $mp1 = $c->modelProblem();
$setEnv(['CLAUDE_CODE_OAUTH_TOKEN' => null, 'HOME' => "$d/nohome"]); $mp2 = $c->modelProblem(); $restoreEnv();
check('config: Claude Code installed, signed in or not', $mp1 === null && $mp2 === 'no-key' && $c->search === 'builtin');
$c = $cfgOf(['TALIXIO_PROVIDER' => 'mystery']);
check('config: an unknown provider has no model names and no problem to report', $c->tiers['standard']->model === '' && $c->modelProblem() === null);
$cwd = getcwd(); chdir($d);
$c = $cfgOf(['TALIXIO_DATA_DIR' => 'relative-data']);
chdir($cwd);
check('config: a relative data folder is made absolute', $c->dataDir === "$d/relative-data");
$setEnv(['TALIXIO_BASE_PATH' => '/fred/']); $bp1 = Config::basePath();
$setEnv(['TALIXIO_BASE_PATH' => 'bad path!']); $bp2 = Config::basePath();
$restoreEnv();
check('config: the base path is cleaned, a bad one ignored', $bp1 === '/fred' && $bp2 === '');

// ======================= Sovereign =======================
check('sovereign: Mistral with dated names only picks the newest of each family', Sovereign::choose('mistral', ['mistral-small-2501', 'mistral-small-2506', 'mistral-large-2411'])
    === ['fast' => 'mistral-small-2506', 'standard' => 'mistral-small-2506', 'deep' => 'mistral-large-2411']);
check('sovereign: Mistral without Small or Medium keeps the fallbacks for those', Sovereign::choose('mistral', ['mistral-large-2411', 'open-mistral-nemo'])
    === ['fast' => 'mistral-small-latest', 'standard' => 'mistral-small-latest', 'deep' => 'mistral-large-2411']);
check('sovereign: only non-chat models: the fallbacks', Sovereign::choose('scaleway', ['bge-m3', 'whisper-large-v3']) === Sovereign::info('scaleway')['fallback']);
check('sovereign: no sizes in the names: the listed fallbacks, else the first model', Sovereign::choose('infomaniak', ['qwen3', 'llama'])
    === ['fast' => 'qwen3', 'standard' => 'qwen3', 'deep' => 'qwen3'] && Sovereign::choose('infomaniak', ['mistral3', 'other']) === ['fast' => 'mistral3', 'standard' => 'mistral3', 'deep' => 'mistral3']);
check('sovereign: small models only: the smallest for fast, the middle one for standard', Sovereign::choose('scaleway', ['tiny-1b', 'small-3b', 'mid-7b'])
    === ['fast' => 'tiny-1b', 'standard' => 'small-3b', 'deep' => 'mid-7b']);
check('sovereign: Mistral\'s moving -latest names come first', Sovereign::choose('mistral', ['mistral-small-latest', 'mistral-small-2506', 'mistral-medium-latest'])
    === ['fast' => 'mistral-small-latest', 'standard' => 'mistral-small-latest', 'deep' => 'mistral-medium-latest']);
$cat = Sovereign::catalog();
check('sovereign: the Settings catalog lists each service with its address, no internals', count($cat) >= 3 && isset($cat[0]['id'], $cat[0]['name'], $cat[0]['url'], $cat[0]['fallback']) && !isset($cat[0]['embed']));
check('sovereign: an unknown service reads as Mistral', Sovereign::info('nope')['name'] === 'Mistral AI');
check('sovereign: services are found by their address', Sovereign::serviceOf('https://oai.endpoints.kepler.ai.cloud.ovh.net/v1') === 'ovhcloud'
    && Sovereign::serviceOf('https://api.mistral.ai/v1') === 'mistral' && Sovereign::serviceOf('https://api.acme.example/v1') === null);
check('sovereign: a test of an unknown provider or without its key stops before asking', str_contains($err(fn() => Sovereign::test('nope', 'k', '')), 'Unknown provider')
    && str_contains($err(fn() => Sovereign::test('mistral', '', 'https://api.acme.example/v1')), 'Paste your API key'));
check('sovereign: a test needs an https address', str_contains($err(fn() => Sovereign::test('ovhcloud', '', 'http://127.0.0.1:1/v1')), 'https://'));
$sa = $mkApp(['TALIXIO_PROVIDER' => 'sovereign', 'TALIXIO_SOVEREIGN' => 'scaleway', 'TALIXIO_SOVEREIGN_KEY' => 'sk-eu', 'TALIXIO_SOVEREIGN_URL' => "$F/v1"]);
$restoreEnv();
$sc = $sa->cfg;
$blank = function () use ($sc) { foreach ($sc->tiers as $t) $t->model = ''; };
$blank(); $sa->store->set('sovereign_models', '');
$routes(['GET /v1/models' => [200, ['data' => [['id' => 'gpt-oss-120b'], ['id' => 'mistral-small-3.2-24b-instruct-2506'], ['id' => 'qwen3-235b-a22b'], ['id' => 'bge-m3']]]]]);
Sovereign::apply($sc, $sa->store);
check('sovereign: the tiers are chosen from the models the key can use', $sc->tiers['fast']->model === 'mistral-small-3.2-24b-instruct-2506' && $sc->tiers['standard']->model === 'gpt-oss-120b'
    && $sc->tiers['deep']->model === 'qwen3-235b-a22b' && ($calls()[0]['auth'] ?? '') === 'Bearer sk-eu');
$blank(); $routes([]);
Sovereign::apply($sc, $sa->store);
check('sovereign: the list is kept for a day', $calls() === [] && $sc->tiers['standard']->model === 'gpt-oss-120b');
Sovereign::apply($sc, $sa->store);
check('sovereign: nothing to fill in, nothing asked', $calls() === []);
$old = json_decode($sa->store->get('sovereign_models'), true); $sa->store->set('sovereign_models', json_encode(['v' => 1] + $old));
$blank(); $routes(['GET /v1/models' => [200, ['data' => [['id' => 'gpt-oss-120b']]]]]);
Sovereign::apply($sc, $sa->store);
check('sovereign: a list saved by an older version is asked again', count($calls()) === 1 && $sc->tiers['deep']->model === 'gpt-oss-120b');
$blank(); $sa->store->set('sovereign_models', ''); $routes(['GET /v1/models' => [500, ['data' => []]]]);
Sovereign::apply($sc, $sa->store);
check('sovereign: a failing list falls back to the known models', $sc->tiers['standard']->model === 'gpt-oss-120b' && $sc->tiers['fast']->model === 'mistral-small-3.2-24b-instruct-2506' && count($calls()) === 1);
$blank(); $sc->sovereignUrl = ''; $sc->sovereignKey = '';
Sovereign::apply($sc, $sa->store);
check('sovereign: no address: the fallbacks, nothing asked', $sc->tiers['deep']->model === 'qwen3-235b-a22b-instruct-2507');
// what still leaves Europe
$oa = $mkApp(['TALIXIO_PROVIDER' => 'anthropic', 'ANTHROPIC_API_KEY' => 'k', 'TALIXIO_TRANSCRIBE' => 'openai', 'TALIXIO_EMBEDDINGS' => 'voyage', 'TALIXIO_SEARCH' => 'brave',
    'TALIXIO_NOTIFY' => 'telegram', 'TALIXIO_TELEGRAM_TOKEN' => '123:abc', 'TALIXIO_GOOGLE_CLIENT_ID' => 'g', 'TALIXIO_GOOGLE_CLIENT_SECRET' => 'gs',
    'TALIXIO_MICROSOFT_CLIENT_ID' => 'm', 'TALIXIO_MICROSOFT_CLIENT_SECRET' => 'ms', 'TALIXIO_SPOTIFY_CLIENT_ID' => 's', 'TALIXIO_SPOTIFY_CLIENT_SECRET' => 'ss']);
$restoreEnv();
foreach (['google', 'microsoft', 'spotify'] as $svc) file_put_contents($oa->cfg->dataDir . "/$svc.json", json_encode(['refresh_token' => 'r', 'access_token' => 'a', 'expires' => time() + 3600]));
$oa->mcp()->saveRaw(json_encode(['mcpServers' => ['far' => ['url' => 'https://mcp.acme.example/mcp'], 'near' => ['url' => 'http://127.0.0.1:9/mcp'], 'cmd' => ['command' => 'true']]]));
$names = array_column(Sovereign::outside($oa), 'name');
check('sovereign: everything that still leaves Europe is listed', $names === ['AI model', 'Transcription', 'Search by meaning', 'Web search', 'Telegram', 'Google Calendar', 'Outlook calendar', 'Spotify', 'Connector far'], json_encode($names));

// ======================= Updater =======================
$ua = $mkApp(['TALIXIO_AUTO_UPDATE' => '1', 'TALIXIO_NOTIFY' => 'ntfy', 'TALIXIO_NTFY_URL' => "$F/ntfy"]);
$restoreEnv();
$up = $ua->updater();
check('updater: no status yet reads as unknown', $up->status() === ['state' => 'unknown'] || is_file(Updater::STATE_DIR . '/status.json'));
check('updater: the version is the app\'s', $up->version() === \Talixio\Version::get());
$setEnv(['TALIXIO_MANAGED' => '1']);
check('updater: a managed assistant is updated by its administrator', str_contains($err(fn() => $up->request('check')), 'administrator'));
$restoreEnv();
$setEnv(['TALIXIO_MANAGED' => null]);
check('updater: requests need an install.sh installation', $up->installed() || str_contains($err(fn() => $up->request('check')), 'git clone + install.sh'));
$restoreEnv();
check('updater: an unknown request is refused', str_contains($err(fn() => $up->request('downgrade')), 'unknown request'));
$notified = function () use ($ua) { return $ua->store->get('update_notified'); };
$up->notifyIfNew();
check('updater: no update available, nothing said', $notified() === '');
if (!is_file(Updater::STATE_DIR . '/status.json')) {
    file_put_contents($ua->cfg->dataDir . '/update-status.json', json_encode(['state' => 'available', 'latest' => 'v9.9', 'commits' => [['subject' => 'Faster search'], ['subject' => 'Fixes']]]));
    file_put_contents($ua->cfg->dataDir . '/update-status.json', json_encode(['state' => 'available', 'latest' => '']));
    $up->notifyIfNew();
    check('updater: available but no version named, nothing said; the status is read from the data folder', $notified() === '' && $up->status()['state'] === 'available');
    file_put_contents($ua->cfg->dataDir . '/update-status.json', json_encode(['state' => 'available', 'latest' => 'v9.9', 'commits' => [['subject' => 'Faster search'], ['subject' => 'Fixes']]]));
    $routes(['POST /ntfy' => [200, '{}']]);
    $up->notifyIfNew();
    $up->notifyIfNew();
    $nc = $calls();
    check('updater: a new version is announced once, with its changes', $notified() === 'v9.9' && count($nc) === 1 && str_contains($nc[0]['body'], '- Faster search')
        && str_contains($nc[0]['body'], 'A new version is available.') && str_contains($nc[0]['body'], 'installs itself tonight'), json_encode($nc));
}

// ======================= Doctor =======================
$da = $mkApp(['TALIXIO_EMBEDDINGS' => 'ollama', 'TALIXIO_OLLAMA_URL' => "$F/ollama", 'TALIXIO_EMBEDDINGS_MODEL' => 'nomic-embed-text', 'TALIXIO_TRANSCRIBE' => 'local',
    'TALIXIO_SMTP_HOST' => '127.0.0.1', 'TALIXIO_SMTP_PORT' => (string)$closed, 'TALIXIO_SMTP_SECURITY' => 'none', 'TALIXIO_EMAIL_FROM' => 'ada@acme.example',
    'TALIXIO_IMAP_HOST' => '127.0.0.1', 'TALIXIO_IMAP_PORT' => (string)$closed, 'TALIXIO_IMAP_SECURITY' => 'none', 'TALIXIO_CALENDAR_ICS' => "$F/cal.ics",
    'TALIXIO_MAILBOX_IMAP_HOST' => '127.0.0.1', 'TALIXIO_MAILBOX_IMAP_PORT' => (string)$closed, 'TALIXIO_MAILBOX_IMAP_SECURITY' => 'none',
    'TALIXIO_BROWSER' => 'on', 'TALIXIO_CHROME_BIN' => PHP_BINARY, 'TALIXIO_SHELL' => 'ask', 'TALIXIO_SIGNAL_NUMBER' => '+15550000', 'TALIXIO_SIGNAL_CLI' => "$d/no-such-signal-cli"]);
$restoreEnv();
\Talixio\Settings::setPassword($da->cfg->dataDir, 'a-long-enough-pass');
$da->mcp()->saveRaw(json_encode(['mcpServers' => ['good' => ['command' => 'true'], 'bad' => ['command' => 'false'], 'gone' => ['command' => 'true', 'disabled' => true]]]));
file_put_contents($da->cfg->dataDir . '/mcp-cache.json', json_encode(['good' => ['tools' => [['name' => 'a'], ['name' => 'b']], 'error' => null], 'bad' => ['tools' => [], 'error' => 'it crashed'],
    'gone' => ['tools' => [], 'error' => null]]));
$routes(['GET /ollama/api/tags' => [200, ['models' => [['name' => 'other:latest']]]], 'GET /cal.ics' => [200, "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", ['Content-Type: text/calendar']]]);
$dm = new FakeModel();
$dm->script = function () use (&$dmN) { $dmN++; return match ($dmN) { 1 => 'pong', 2 => 'something else', default => throw new \RuntimeException('model down') }; };
$dmN = 0;
$da->providerOverride = $dm;
$dr = (new Doctor($da))->run();
$byText = function (string $prefix) use (&$dr): ?array { foreach ($dr as $r) if (str_starts_with($r['text'], $prefix)) return $r; return null; };
check('doctor: the password is set', ($byText('app password set')['status'] ?? '') === 'ok');
check('doctor: an Ollama model that is not installed fails', ($byText('search by meaning (ollama: nomic-embed-text)')['status'] ?? '') === 'fail' && str_contains($byText('search by meaning')['text'], 'ollama pull nomic-embed-text'));
check('doctor: local transcription without whisper.cpp fails with a hint', ($byText('transcription: local, whisper.cpp not installed')['status'] ?? '') === 'fail');
check('doctor: SMTP and IMAP that do not answer fail', ($byText('SMTP 127.0.0.1')['status'] ?? '') === 'fail' && ($byText('IMAP 127.0.0.1')['status'] ?? '') === 'fail');
check('doctor: no allowed senders fails', ($byText('allowed senders: none')['status'] ?? '') === 'fail');
check('doctor: the calendar is read', ($byText('calendar')['text'] ?? '') !== '' && str_contains($byText('calendar')['text'] . ' ', 'event'), json_encode($byText('calendar')));
check('doctor: a mailbox that does not answer fails', ($byText('your mailbox')['status'] ?? '') === 'fail');
check('doctor: the browser binary is found', ($byText('browser: ')['status'] ?? '') === 'ok');
check('doctor: the sandboxed shell is checked', $byText('sandboxed shell (ask)') !== null);
check('doctor: connectors are listed with their tools or errors, disabled ones skipped', ($byText('connector good: 2 tool(s)')['status'] ?? '') === 'ok'
    && ($byText('connector bad: it crashed')['status'] ?? '') === 'fail' && $byText('connector gone') === null);
check('doctor: a signal-cli that is missing fails, and no allowed numbers', ($byText('signal-cli (')['status'] ?? '') === 'fail' && ($byText('Signal numbers allowed')['status'] ?? '') === 'fail');
check('doctor: each tier is asked for pong', ($byText('fast tier')['status'] ?? '') === 'ok' && str_contains($byText('standard tier')['text'], 'unexpected answer: something else')
    && str_contains($byText('deep tier')['text'], 'model down'));
// an installed Ollama model is used; a European provider locked to Europe gets the stamp
$routes(['GET /ollama/api/tags' => [200, ['models' => [['name' => 'nomic-embed-text:latest']]]], 'POST /ollama/api/embed' => [200, ['embeddings' => [[0.1, 0.2, 0.3, 0.4]]]]]);
$da->cfg->transcribe = 'gemini';
$dr = (new Doctor($da))->run(false);
check('doctor: an installed Ollama model answers with its size', ($byText('search by meaning')['status'] ?? '') === 'ok' && str_contains($byText('search by meaning')['text'], '4 dimensions'), json_encode($byText('search by meaning')));
check('doctor: a transcription service is only mentioned', ($byText('transcription: gemini')['status'] ?? '') === 'info' && $byText('fast tier') === null);
$ea = $mkApp(['TALIXIO_PROVIDER' => 'sovereign', 'TALIXIO_SOVEREIGN' => 'mistral', 'TALIXIO_SOVEREIGN_KEY' => 'k', 'TALIXIO_SOVEREIGN_URL' => 'http://127.0.0.1:9/v1', 'TALIXIO_SOVEREIGN_ONLY' => '1', 'TALIXIO_SEARCH' => 'off', 'TALIXIO_EMBEDDINGS' => 'off', 'TALIXIO_TRANSCRIBE' => 'off']);
$restoreEnv();
$dr = (new Doctor($ea))->run(false);
check('doctor: everything in Europe, locked', ($byText('Everything stays in Europe: Mistral AI (France)')['status'] ?? '') === 'eu' && str_contains($byText('Everything stays')['text'], ', locked'));
$ea->cfg->telegramToken = '1:x'; $ea->cfg->notify = ['telegram'];
$dr = (new Doctor($ea))->run(false);
check('doctor: something outside Europe fails the stamp', ($byText('Europe only: still outside Europe: Telegram')['status'] ?? '') === 'fail');

// ======================= Transcriber =======================
$ta = $mkApp(['TALIXIO_TRANSCRIBE' => 'off']);
$restoreEnv();
$tc = $ta->cfg;
$tr = new Transcriber($ta);
$td = "$d/audio"; @mkdir($td);
exec('ffmpeg -nostdin -loglevel error -y -f lavfi -i sine=frequency=440:duration=1 ' . escapeshellarg("$td/beep.wav") . ' 2>&1', $o, $fc);
exec('ffmpeg -nostdin -loglevel error -y -f lavfi -i sine=frequency=440:duration=1 -c:a libopus ' . escapeshellarg("$td/beep.webm") . ' 2>&1', $o, $fc2);
file_put_contents("$td/not-audio.txt", 'just text');
$beep = ['path' => "$td/beep.wav"];
check('transcriber: off says how to turn it on', !$tr->enabled() && str_contains($err(fn() => $tr->transcribe($beep, false)), 'transcription is off'));
$tc->transcribe = 'carrier-pigeon';
check('transcriber: an unknown mode is refused', str_contains($err(fn() => $tr->transcribe($beep, false)), 'unknown TALIXIO_TRANSCRIBE carrier-pigeon'));
// a European provider through the OpenAI audio API
$tc->transcribe = 'sovereign'; $tc->sovereign = 'scaleway'; $tc->sovereignUrl = "$F/eu/v1"; $tc->sovereignKey = 'sk-eu'; $tc->transcribeModel = 'whisper-large-v3';
$routes(['POST /eu/v1/audio/transcriptions' => [200, ['text' => "  Hello Alex.  "]]]);
$out = $tr->transcribe($beep, false);
check('transcriber: a European provider transcribes through its audio API', $out === 'Hello Alex.' && ($calls()[0]['auth'] ?? '') === 'Bearer sk-eu' && str_contains($calls()[0]['body'] ?? '', 'whisper-large-v3'), $out);
$tc->transcribeModel = 'large-v3-turbo-q5_0'; $tc->sovereignKey = '';
$routes(['POST /eu/v1/audio/transcriptions' => [500, ['error' => 'busy']]]);
$e1 = $err(fn() => $tr->transcribe($beep, false));
check('transcriber: a model name that is not theirs is replaced; errors are reported', str_contains($e1, 'Scaleway transcription failed (500)') && str_contains($calls()[0]['body'] ?? '', 'whisper-large-v3')
    && ($calls()[0]['auth'] ?? '') === '', $e1);
// Gemini
$tc->transcribe = 'gemini'; $tc->geminiKey = 'gk'; $tc->geminiBaseUrl = "$F/gem/v1beta"; $tc->transcribeModel = 'gemini-2.5-flash';
$routes(['POST /gem/v1beta/models/gemini-2.5-flash:generateContent' => [200, ['candidates' => [['content' => ['parts' => [['text' => '[00:00] Alex: hi']]]]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]]]]);
$out = $tr->transcribe($beep, true);
$gb = json_decode($calls()[0]['body'] ?? '', true) ?: [];
check('transcriber: Gemini with Flash 2.5 thinks not at all and counts the usage', $out === '[00:00] Alex: hi' && ($gb['generationConfig']['thinkingConfig']['thinkingBudget'] ?? null) === 0
    && ($gb['contents'][0]['parts'][0]['inline_data']['mime_type'] ?? '') === 'audio/ogg' && (int)$ta->store->q("SELECT COUNT(*) FROM usage WHERE source = 'transcription'")->fetchColumn() >= 1, $out);
$tc->transcribeModel = 'small'; $tc->provider = 'gemini'; $tc->tiers['standard']->model = 'gemini-3-pro';
$routes(['POST /gem/v1beta/models/gemini-3-pro:generateContent' => [200, ['candidates' => [['finishReason' => 'SAFETY']]]]]);
$e1 = $err(fn() => $tr->transcribe($beep, false));
check('transcriber: Gemini 3 thinks little; no text is an error with the reason', str_contains($e1, 'no transcript (SAFETY)') && str_contains($calls()[0]['body'] ?? '', '"thinkingLevel":"low"'), $e1);
$tc->provider = 'echo';
$origPath = getenv('PATH');
$setEnv(['PATH' => "$d/empty-bin"]); @mkdir("$d/empty-bin");
$routes(['POST /gem/v1beta/models/' . \Talixio\GeminiModels::FALLBACK['standard'] . ':generateContent' => [200, ['candidates' => [['content' => ['parts' => [['text' => 'webm ok']]]]]]]]);
$out = $tr->transcribe(['path' => "$td/beep.webm"], false);
$restoreEnv();
check('transcriber: without ffmpeg the recording is sent as it is, a WebM as audio', $fc2 !== 0 || ($out === 'webm ok' && str_contains($calls()[0]['body'] ?? '', '"mime_type":"audio/webm"')), $out);
// a large recording goes through the Files API
$big = "$td/big.bin"; file_put_contents($big, str_repeat('A', 14_000_001));
$routes(['POST /gem/upload/v1beta/files' => [200, '{}', ['X-Goog-Upload-URL: ' . "$F/up"]], 'POST /up' => [200, ['file' => ['name' => 'files/abc', 'uri' => 'gs://abc', 'state' => 'PROCESSING']]],
    'GET /gem/v1beta/files/abc' => [200, ['name' => 'files/abc', 'uri' => 'gs://abc', 'state' => 'ACTIVE']], 'DELETE /gem/v1beta/files/abc' => [200, '{}'],
    'POST /gem/v1beta/models/' . \Talixio\GeminiModels::FALLBACK['standard'] . ':generateContent' => [200, ['candidates' => [['content' => ['parts' => [['text' => 'long one']]]]]]]]);
$out = $tr->transcribe(['path' => $big], true);
$paths = array_map(fn($c) => $c['m'] . ' ' . $c['path'], $calls());
check('transcriber: a large recording is uploaded, waited for, used and deleted', $out === 'long one' && in_array('GET /gem/v1beta/files/abc', $paths, true) && in_array('DELETE /gem/v1beta/files/abc', $paths, true)
    && str_contains(implode(' ', array_column($calls(), 'body')), 'gs://abc'), json_encode($paths));
$routes(['POST /gem/upload/v1beta/files' => [200, '{}', ['X-Goog-Upload-URL: ' . "$F/up"]], 'POST /up' => [200, ['file' => ['name' => 'files/abc', 'state' => 'FAILED']]]]);
check('transcriber: a file Gemini cannot process is an error', str_contains($err(fn() => $tr->transcribe(['path' => $big], true)), 'could not process the audio file'));
$routes(['POST /up' => [500, 'nope']]);
check('transcriber: an upload that cannot start is an error', str_contains($err(fn() => $tr->transcribe(['path' => $big], true)), 'Gemini upload start failed (404)'));
$routes(['POST /gem/upload/v1beta/files' => [200, '{}', ['X-Goog-Upload-URL: ' . "$F/up"]], 'POST /up' => [500, 'nope']]);
check('transcriber: an upload that fails is an error', str_contains($err(fn() => $tr->transcribe(['path' => $big], true)), 'Gemini upload failed (500)'));
@unlink($big);
// local, with a stand-in whisper-cli
$wb = "$d/wbin"; @mkdir($wb);
file_put_contents("$wb/whisper-cli", <<<'SH'
#!/bin/sh
of=""; while [ $# -gt 0 ]; do [ "$1" = "-of" ] && of="$2"; shift; done
case "$FAKE_WHISPER" in
  oom) exit 137 ;;
  fail) echo "boom"; exit 3 ;;
  quiet) printf '{"transcription":[{"text":" [Music] ","offsets":{"from":0}},{"text":"","offsets":{"from":10}}]}' > "$of.json" ;;
  *) printf '{"transcription":[{"text":" Hello ","offsets":{"from":0}},{"text":"(silence)","offsets":{"from":1000}},{"text":"Alex here","offsets":{"from":65000}}]}' > "$of.json" ;;
esac
exit 0
SH);
chmod("$wb/whisper-cli", 0755);
file_put_contents("$d/ggml-test.bin", 'model');
$tc->transcribe = 'local'; $tc->transcribeModel = "$d/ggml-test.bin";
$setEnv(['PATH' => "$d/empty-bin"]);
check('transcriber: local without whisper.cpp says how to install it', str_contains($err(fn() => $tr->transcribe($beep, false)), 'whisper.cpp is not installed (sudo ./install.sh --with-whisper)'));
$setEnv(['TALIXIO_MANAGED' => '1']);
check('transcriber: on a managed server the administrator installs it', str_contains(Transcriber::whisperHint(), 'install-stack.sh'));
$restoreEnv();
$setEnv(['PATH' => "$wb:$origPath"]);
$tc->transcribeModel = "$d/no-model.bin";
check('transcriber: local without its model file says so', str_contains($err(fn() => $tr->transcribe($beep, false)), 'model file not found'));
$tc->transcribeModel = '';
[, $wm] = Transcriber::whisper($tc);
check('transcriber: without a model name, the default (or another installed one) is used', str_starts_with($wm, Transcriber::WHISPER_DIR . '/models/ggml-'));
$tc->transcribeModel = "$d/ggml-test.bin";
check('transcriber: local with audio ffmpeg cannot read is an error', str_contains($err(fn() => $tr->transcribe(['path' => "$td/not-audio.txt"], false)), 'ffmpeg could not read the audio'));
$out = $tr->transcribe($beep, true);
check('transcriber: local meeting transcript has times, skips noise', $out === "[00:00] Hello\n[01:05] Alex here", $out);
check('transcriber: local voice note is one line', $tr->transcribe($beep, false) === 'Hello Alex here');
$setEnv(['FAKE_WHISPER' => 'quiet']);
check('transcriber: only noise is "nothing was heard"', str_contains($err(fn() => $tr->transcribe($beep, false)), 'nothing was heard'));
$setEnv(['FAKE_WHISPER' => 'oom']);
check('transcriber: killed for memory says to choose a smaller model', str_contains($err(fn() => $tr->transcribe($beep, false)), 'not enough memory'));
$setEnv(['FAKE_WHISPER' => 'fail']);
check('transcriber: a failing whisper.cpp is reported with its output', str_contains($err(fn() => $tr->transcribe($beep, false)), 'local transcription failed (3): boom'));
$restoreEnv();

// ======================= BrowserProxy (its own process) =======================
$bp = proc_open([PHP_BINARY, "$root/bin/talixio", 'browser-proxy'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $bpp, null, $fenv + ['TALIXIO_DATA_DIR' => "$d/bp"]);
$bport = (int)trim((string)fgets($bpp[1]));
check('proxy: prints the port it listens on first', $bport > 0);
/** Sends raw bytes (one write per element) and reads until the proxy closes or $wait seconds pass. */
$px = function (array $writes, float $wait = 3.0, ?callable $then = null) use ($bport): string {
    $s = stream_socket_client("tcp://127.0.0.1:$bport", $en, $es, 3);
    foreach ($writes as $w) { fwrite($s, $w); usleep(150000); }
    $out = ''; $until = microtime(true) + $wait;
    stream_set_timeout($s, 0, 200000);
    while (microtime(true) < $until && !feof($s)) {
        $out .= (string)fread($s, 65536);
        if ($then && $then($out, $s)) $then = null;
    }
    fclose($s);
    return $out;
};
check('proxy: a bad request line gets 400', str_starts_with($px(["hello there\r\n\r\n"]), 'HTTP/1.1 400'));
check('proxy: an https:// address in a plain request gets 400', str_contains($px(["GET https://acme.example/ HTTP/1.1\r\nHost: acme.example\r\n\r\n"]), 'only http:// addresses'));
check('proxy: a chunked request body gets 501', str_starts_with($px(["POST http://acme.example/ HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n"]), 'HTTP/1.1 501'));
check('proxy: a head with no end past 64 KiB gets 431', str_starts_with($px(["GET http://acme.example/ HTTP/1.1\r\nX-Pad: " . str_repeat('a', 70000)]), 'HTTP/1.1 431'));
$o = $px(["GET http://127.0.0.1:$fport/ HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n"]);
check('proxy: a local address is refused with 403', str_starts_with($o, 'HTTP/1.1 403') && str_contains($o, 'non-public'));
check('proxy: a tunnel to a local address is refused with 403', str_starts_with($px(["CONNECT 127.0.0.1:$fport HTTP/1.1\r\n\r\n"]), 'HTTP/1.1 403'));
check('proxy: a malformed CONNECT target is dropped without an answer', $px(["CONNECT nope HTTP/1.1\r\n\r\n"], 1.0) === '');
$o = $px(["GET /", "x HTTP/1.1\r\n\r\n"]); // a head split over two writes is joined first
check('proxy: a split head is read whole before it is judged', str_contains($o, 'only http:// addresses'));
check('proxy: a client that just leaves is dropped quietly', $px([], 0.3) === '');

// A "public" upstream: 192.0.2.2 (TEST-NET-1, which Http::isPublic counts as public), when this machine can listen there.
$probe = @stream_socket_server('tcp://192.0.2.2:0');
if ($probe) {
    $uport = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    $up = proc_open([PHP_BINARY, '-S', "192.0.2.2:$uport", "$fakeDir/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $upp, null, $fenv);
    $t0 = microtime(true);
    while (!@fsockopen('192.0.2.2', $uport, $en, $es, 0.2) && microtime(true) - $t0 < 5) usleep(50000);
    $U = "192.0.2.2:$uport";
    $routes(['GET /hi' => [200, ['hi' => 'Alex']], 'POST /in' => [201, 'got it', ['Content-Type: text/plain']]]);
    $o = $px(["GET http://$U/hi?x=1 HTTP/1.1\r\nHost: $U\r\nProxy-Connection: keep-alive\r\nProxy-Authorization: Basic eA==\r\nConnection: close\r\n\r\n"]);
    $c = $calls();
    check('proxy: a public http request is passed on and answered', str_contains($o, '200 OK') && str_contains($o, '"hi":"Alex"'));
    check('proxy: the request goes on with its path and query', ($c[0]['path'] ?? '') === '/hi' && ($c[0]['q']['x'] ?? '') === '1');
    $routes(['GET /hi' => [200, ['hi' => 'Alex']], 'POST /in' => [201, 'got it', ['Content-Type: text/plain']]]);
    $o = $px(["POST http://$U/in HTTP/1.1\r\nHost: $U\r\nContent-Type: text/plain\r\nContent-Length: 10\r\n\r\n", "hello", "world"]);
    $c = $calls();
    check('proxy: a request body that comes in parts is passed on whole', ($c[0]['body'] ?? '') === 'helloworld' && str_contains($o, 'got it'));
    $o = $px(["CONNECT $U HTTP/1.1\r\nHost: $U\r\n\r\nGET /hi HTTP/1.1\r\nHost: $U\r\n\r\n"], 1.5);
    check('proxy: CONNECT opens a tunnel and early bytes go through it', str_starts_with($o, "HTTP/1.1 200 Connection established") && str_contains($o, '"hi":"Alex"'));
    $o = $px(["CONNECT $U HTTP/1.1\r\n\r\n", "GET /hi HTTP/1.1\r\nHost: $U\r\nConnection: close\r\n\r\n"]);
    check('proxy: bytes after the tunnel opens go through it', substr_count($o, '"hi":"Alex"') === 1);
    $dead = free_port();
    $o = $px(["GET http://192.0.2.2:$dead/ HTTP/1.1\r\n\r\n"], 12.0);
    check('proxy: an upstream that does not answer gets 502', str_starts_with($o, 'HTTP/1.1 502'));
    $routes(['GET /hi' => [200, ['hi' => 'Alex']]]);
    // a raw upstream that answers each connection with everything it got on it
    $eport = free_port();
    file_put_contents("$fakeDir/echo.php", '<?php $s = stream_socket_server($argv[1]); while ($c = @stream_socket_accept($s, 30)) { stream_set_timeout($c, 1); $in = ""; while (!str_contains($in, "/two")) { $r = fread($c, 9999); if ($r === "" || $r === false) break; $in .= $r; } fwrite($c, "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($in) . "\r\nConnection: close\r\n\r\n$in"); fclose($c); }');
    $ep = proc_open([PHP_BINARY, "$fakeDir/echo.php", "tcp://192.0.2.2:$eport"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $epp, null, $fenv);
    $t0 = microtime(true);
    while (!@fsockopen('192.0.2.2', $eport, $en, $es, 0.2) && microtime(true) - $t0 < 5) usleep(50000);
    $E = "192.0.2.2:$eport";
    $o = $px(["GET http://$E/one HTTP/1.1\r\nHost: $E\r\n\r\nGET http://$E/two HTTP/1.1\r\nHost: $E\r\n\r\n"], 4.0);
    check('proxy: requests on one connection to the same host reuse the upstream', str_contains($o, "GET /one HTTP/1.1") && str_contains($o, "GET /two HTTP/1.1"));
    proc_terminate($ep); proc_close($ep);
    $routes(['POST /in' => [201, 'got it']]);
    $o = $px(["POST http://$U/in HTTP/1.1\r\nContent-Length: 4\r\n\r\n", "abcdGET http://nothing.invalid/ HTTP/1.1\r\n\r\n"], 4.0);
    $c = $calls();
    check('proxy: after a body, the next request on the line is judged by itself', str_contains($o, 'cannot resolve nothing.invalid') && ($c[0]['body'] ?? 'abcd') === 'abcd');
    proc_terminate($up); proc_close($up);
} else {
    check('proxy: (no 192.0.2.2 here, public upstream checks skipped)', true);
}
fclose($bpp[0]); // the parent leaving ends the proxy
$t0 = microtime(true);
while (proc_get_status($bp)['running'] && microtime(true) - $t0 < 5) usleep(50000);
check('proxy: ends when its parent closes stdin', !proc_get_status($bp)['running']);
proc_close($bp);

// ======================= ConnectorPackage =======================
$app = $mkApp();
$cp = new ConnectorPackage($app);
$cpDir = $app->cfg->dataDir;
$zn = 0;
/** A .zip in /Connecteurs from [name => content | ['link' => target] | ['exec' => content] | ['size' => bytes]]. */
$zipOf = function (string $name, array $files) use ($app, $d, &$zn): int {
    $p = "$d/z" . (++$zn) . '.zip';
    $z = new \ZipArchive(); $z->open($p, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    foreach ($files as $n => $c) {
        if (is_array($c) && isset($c['link'])) { $z->addFromString($n, $c['link']); $z->setExternalAttributesName($n, \ZipArchive::OPSYS_UNIX, 0120777 << 16); }
        elseif (is_array($c) && isset($c['exec'])) { $z->addFromString($n, $c['exec']); $z->setExternalAttributesName($n, \ZipArchive::OPSYS_UNIX, 0100755 << 16); }
        elseif (is_array($c) && isset($c['size'])) $z->addFromString($n, str_repeat("\0", $c['size']));
        else $z->addFromString($n, $c);
    }
    $z->close();
    \Talixio\Library::$system = true;
    try { return $app->files()->save($p, $name, 'application/zip', ConnectorPackage::FOLDER); } finally { \Talixio\Library::$system = false; }
};
$i1 = $cp->inspect($zipOf('a.zip', ['package.json' => json_encode(['name' => '@acme/alex-tools', 'bin' => './missing.js', 'main' => './lib/main.js', 'dependencies' => ['x' => '1']]),
    'lib/main.js' => 'const a = process.env["ALEX_TOKEN"];', 'node_modules/x/index.js' => 'process.env.NOT_READ_HERE']));
check('connector: "bin" missing in the zip falls back to "main"', $i1['entry'] === 'lib/main.js' && $i1['name'] === 'alex-tools' && $i1['env'] === ['ALEX_TOKEN']);
check('connector: dependencies already in node_modules need no install', $i1['deps'] === false);
$i2 = $cp->inspect($zipOf('b.zip', ['package.json' => json_encode(['bin' => ['b' => 'cli.js']]), 'cli.js' => 'x', '.env.example' => "B_URL=https://acme.example\nPORT=3\n"]));
check('connector: a "bin" map uses its first command; .env.example settings are read, common ones skipped', $i2['entry'] === 'cli.js' && $i2['env'] === ['B_URL'] && $i2['name'] === 'b');
$i3 = $cp->inspect($zipOf('py.zip', ['srv/server.py' => "import os\nk = os.environ.get('PY_KEY')\nu = os.getenv('PY_URL')\n", 'srv/README.md' => 'x']));
check('connector: a Python server is found with the settings it reads', $i3['runtime'] === 'python' && $i3['entry'] === 'server.py' && $i3['env'] === ['PY_KEY', 'PY_URL'] && $i3['name'] === 'py');
$i4 = $cp->inspect($zipOf('m.zip', ['index.mjs' => 'export {}', 'a.js' => str_repeat('// filler ', 450000), 'b.js' => 'process.env.LATE_KEY']));
check('connector: .mjs start file found; reading stops after 4 MB of source', $i4['entry'] === 'index.mjs' && !in_array('LATE_KEY', $i4['env'], true));
$i5 = $cp->inspect($zipOf('bld.zip', ['package.json' => json_encode(['scripts' => ['build' => 'tsc']]), 'src/x.ts' => 'x']));
check('connector: no start file but a build script: to be built', $i5['entry'] === null && $i5['build'] === true);
$noSrv = $zipOf('none.zip', ['notes.txt' => 'x', 'more.txt' => 'y']);
check('connector: a zip without a server cannot be installed', str_contains($err(fn() => $cp->install($noSrv, 'none', [], false)), 'No server found'));
$setEnv(['PATH' => "$d/emptybin"]); @mkdir("$d/emptybin");
check('connector: a Node server without Node.js says how to get it', str_contains($err(fn() => $cp->install($zipOf('n.zip', ['index.js' => 'x']), 'n', [], false)), 'Node.js is not installed'));
$setEnv(['PATH' => getenv('PATH') === "$d/emptybin" ? $fenv['PATH'] : getenv('PATH')]);
$big = $zipOf('big.zip', ['server.py' => 'x', 'blob.bin' => ['size' => (80 << 20) + 10]]);
check('connector: a zip unpacking past 80 MB is refused and nothing is left', str_contains($err(fn() => $cp->install($big, 'big', [], false)), 'too big') && !glob("$cpDir/connectors/.big-*"));

// a Python server from GitHub, with a link and an executable file, installed (its server does not answer)
$ghz = "$d/ghpy.zip"; $z = new \ZipArchive(); $z->open($ghz, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
$z->addFromString('pyt-dev/server.py', "import sys\nsys.exit(0)\n"); $z->addFromString('pyt-dev/run.sh', "#!/bin/sh\n");
$z->setExternalAttributesName('pyt-dev/run.sh', \ZipArchive::OPSYS_UNIX, 0100755 << 16);
$z->addFromString('pyt-dev/link', '/etc/passwd'); $z->setExternalAttributesName('pyt-dev/link', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
$z->addFromString('pyt-dev/sub/link2', '/etc/passwd'); $z->setExternalAttributesName('pyt-dev/sub/link2', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
$z->addFromString('pyt-dev/sub/server.py', 'x');
$z->close();
ConnectorPackage::$fetch = fn(string $u) => file_get_contents($ghz);
$g = $cp->fromGithub('https://github.com/acme/pyt/tree/dev');
check('connector from GitHub: a branch without a folder keeps the branch in its address', $g['github'] === 'https://github.com/acme/pyt/tree/dev' && $g['runtime'] === 'python');
$gs = $cp->fromGithub('https://github.com/acme/pyt/tree/dev/sub');
$gz = new \ZipArchive(); $gz->open($app->files()->get($gs['file'])['path']); $gnames = []; for ($k = 0; $k < $gz->numFiles; $k++) $gnames[] = $gz->getNameIndex($k); $gz->close();
check('connector from GitHub: links are left out of a sub-folder', $gnames === ['server.py']);
ConnectorPackage::$fetch = fn(string $u) => 'not a zip';
check('connector from GitHub: something else than a zip is said', str_contains($err(fn() => $cp->fromGithub('https://github.com/acme/pyt')), 'not a .zip archive'));
$hz = "$d/ghbig.zip"; $z = new \ZipArchive(); $z->open($hz, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
$z->addFromString('r-main/s/server.py', 'x'); $z->addFromString('r-main/s/blob.bin', str_repeat("\0", (80 << 20) + 10)); $z->close();
ConnectorPackage::$fetch = fn(string $u) => file_get_contents($hz);
check('connector from GitHub: a sub-folder past 80 MB is refused', str_contains($err(fn() => $cp->fromGithub('https://github.com/acme/r/tree/main/s')), 'too big for a connector'));
ConnectorPackage::$fetch = null;
@unlink($hz);

$cp->install($g['file'], 'pyt', ['PY_KEY' => 'KEY-123', 'PY_URL' => 'https://acme.example', 'lower' => 'x'], false);
$st = json_decode($app->store->get('connector_install', ''), true);
check('connector: the GitHub address is kept with the install', ($st['github'] ?? '') === 'https://github.com/acme/pyt/tree/dev' && $st['runtime'] === 'python' && $st['env'] === ['PY_KEY' => 'KEY-123', 'PY_URL' => 'https://acme.example']);
$tmpDir = "$cpDir/connectors/" . $st['tmp'];
check('connector: links are not unpacked, executables stay executable', !file_exists("$tmpDir/link") && !is_link("$tmpDir/link") && (fileperms("$tmpDir/run.sh") & 0100));
$cp->finish();
$cs = $cp->status(); $srv = $app->mcp()->servers()['pyt'] ?? [];
check('connector: a server that does not answer is installed but said failing', $cs['state'] === 'failed' && str_contains($cs['error'] ?? '', 'did not answer') && ($srv['command'] ?? '') === 'python3'
    && ($srv['source']['github'] ?? '') === 'https://github.com/acme/pyt/tree/dev', json_encode([$cs, $srv]));
$again = $cp->inspect($g['file']);
check('connector: inspecting an installed one gives its values, a value that looks like a key left out', $again['installed'] && $again['values'] === ['PY_KEY' => '', 'PY_URL' => 'https://acme.example'], json_encode($again));
$cp->finish();
check('connector: finish with nothing running does nothing', $cp->status()['state'] === 'failed');

check('connector: the library zips are listed, /Connecteurs first', ($cp->packages()[0]['folder'] ?? '') === ConnectorPackage::FOLDER && count(array_filter($cp->packages(), fn($p) => str_ends_with($p['name'], '.zip'))) === count($cp->packages()));
check('connector from GitHub: other sites and ".." folders are refused', str_contains($err(fn() => ConnectorPackage::parseGithub('https://gitlab.com/acme/x')), 'GitHub repository')
    && str_contains($err(fn() => ConnectorPackage::parseGithub('https://github.com/acme/x/tree/main/a/%2e%2e/b')), 'GitHub repository'));
ConnectorPackage::$fetch = fn(string $u) => file_get_contents($ghz);
check('connector from GitHub: a folder that is not there is said', str_contains($err(fn() => $cp->fromGithub('https://github.com/acme/pyt/tree/dev/nowhere')), 'no folder "nowhere"'));
// the same repository again, now with a server that answers: the old download goes to the trash, the old install is replaced
$z = new \ZipArchive(); $z->open($ghz, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
$z->addFromString('pyt-dev/server.py', <<<'PY'
import sys, json, os
for line in sys.stdin:
    m = json.loads(line)
    if 'id' not in m: continue
    r = {'protocolVersion': '2025-06-18', 'capabilities': {'tools': {}}, 'serverInfo': {'name': 'pyt', 'version': '1'}} if m['method'] == 'initialize' else \
        {'tools': [{'name': 'hello', 'description': 'Says hello to ' + os.environ.get('PY_KEY', ''), 'inputSchema': {'type': 'object', 'properties': {}}}]} if m['method'] == 'tools/list' else {}
    print(json.dumps({'jsonrpc': '2.0', 'id': m['id'], 'result': r}), flush=True)
PY);
$z->close();
$g2 = $cp->fromGithub('https://github.com/acme/pyt/tree/dev');
check('connector from GitHub: fetched again, the earlier copy goes to the trash', !$app->files()->get($g['file']) && $app->files()->get($g2['file']));
ConnectorPackage::$fetch = null;
$cp->install($g2['file'], 'pyt', ['PY_KEY' => '', 'PY_URL' => 'https://acme.example/v2'], false);
$cp->finish();
$cs = $cp->status(); $srv = $app->mcp()->servers()['pyt'] ?? [];
check('connector: reinstalled over the old one, answering, a secret left empty keeps its value', $cs['state'] === 'done' && $cs['tools'] === 1 && ($srv['env']['PY_KEY'] ?? '') === 'KEY-123'
    && ($srv['env']['PY_URL'] ?? '') === 'https://acme.example/v2' && is_file("$cpDir/connectors/pyt/server.py") && str_contains((string)file_get_contents("$cpDir/connectors/pyt/server.py"), 'tools/list'), json_encode([$cs, $srv]));

if (trim((string)shell_exec('command -v npm')) !== '') {
    // built: npm install then npm run build (no dependencies, so no network), the result does not answer either
    $bz = $zipOf('built.zip', ['package.json' => json_encode(['name' => 'built', 'version' => '1.0.0', 'scripts' => ['build' => "node -e \"require('fs').mkdirSync('dist');require('fs').writeFileSync('dist/index.js','')\""]])]);
    $cp->install($bz, 'built', [], false); $cp->finish();
    $cs = $cp->status(); $srv = $app->mcp()->servers()['built'] ?? [];
    check('connector: built with npm, the start file is found in dist/', ($srv['args'][0] ?? '') === 'dist/index.js' && is_file("$cpDir/connectors/built/dist/index.js") && $cs['state'] === 'failed', json_encode($cs));
    $nz = $zipOf('nobuild.zip', ['package.json' => json_encode(['name' => 'nobuild', 'version' => '1.0.0', 'scripts' => ['build' => 'true']])]);
    $cp->install($nz, 'nobuild', [], false); $tmpN = "$cpDir/connectors/" . json_decode($app->store->get('connector_install', ''), true)['tmp']; $cp->finish();
    clearstatcache(); check('connector: a build that makes no server fails and leaves nothing', $cp->status()['error'] === 'no server file after building' && !is_dir($tmpN) && !is_dir("$cpDir/connectors/nobuild"));
    $dz = $zipOf('deps.zip', ['package.json' => json_encode(['name' => 'deps', 'version' => '1.0.0', 'main' => 'index.js', 'dependencies' => ['nowhere' => 'file:./missing.tgz']]), 'index.js' => 'x']);
    $cp->install($dz, 'deps', [], false); $cp->finish();
    check('connector: a failing npm install is said with its step', str_starts_with((string)$cp->status()['error'], 'installing dependencies:') && $cp->status()['state'] === 'failed', json_encode($cp->status()));
}

// browsers for Playwright servers: a window shown needs the full Chromium
$pw = "$d/pw"; @mkdir("$pw/node_modules/playwright-core", 0777, true); @mkdir("$pw/node_modules/patchright-core", 0777, true);
file_put_contents("$pw/node_modules/playwright-core/cli.js", ''); file_put_contents("$pw/node_modules/patchright-core/cli.js", '');
file_put_contents("$pw/node_modules/playwright-core/browsers.json", json_encode(['browsers' => [['name' => 'chromium', 'revision' => '7'], ['name' => 'chromium-headless-shell', 'revision' => '7']]]));
file_put_contents("$pw/node_modules/patchright-core/browsers.json", json_encode(['browsers' => [['name' => 'chromium', 'revision' => '7'], ['name' => 'chromium-headless-shell', 'revision' => '7']]]));
file_put_contents("$pw/app.js", "launch({ headless: false })");
@mkdir("$d/pwdata/ms-playwright/chromium_headless_shell-7", 0777, true); touch("$d/pwdata/ms-playwright/chromium_headless_shell-7/INSTALLATION_COMPLETE");
$cmds = ConnectorPackage::browserInstalls($pw, "$d/pwdata");
check('connector: a server showing its window gets the full Chromium, for each driver', count($cmds) === 2 && !str_contains($cmds[0], '--only-shell') && str_contains($cmds[0], 'patchright-core'));
@mkdir("$d/pwdata/ms-playwright/chromium-7", 0777, true); touch("$d/pwdata/ms-playwright/chromium-7/INSTALLATION_COMPLETE");
check('connector: both Chromiums there, nothing to download', ConnectorPackage::browserInstalls($pw, "$d/pwdata") === []);
$restoreEnv();

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
