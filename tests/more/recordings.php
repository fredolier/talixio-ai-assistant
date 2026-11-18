<?php
declare(strict_types=1);
// 1.41.0: a recording is saved on the server while it is made. The browser sends a piece every few seconds; pieces are
// appended strictly in order (one sent twice is not appended twice, one sent ahead of a missing one is refused with the
// one expected), the finished recording becomes a file like any upload, and an interrupted one is listed to be offered back.
require_once __DIR__ . '/../helpers.php';

$d = test_dir('recordings');
$web = web_app($d, ['TALIXIO_WEB_PASSWORD' => 'pw-recordings-1']);
$port = (int)(new ReflectionFunction($web))->getStaticVariables()['port'];
check('recordings: signed in', $web('/api/login', ['password' => 'pw-recordings-1'])['_code'] === 200);
$piece = function (string $id, int $seq, string $data, string $jar = 'a') use ($port, $d) {
    $ch = curl_init("http://127.0.0.1:$port/api/recordings/$id/chunk?seq=$seq");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data, CURLOPT_COOKIEFILE => "$d/ck-$jar",
        CURLOPT_HTTPHEADER => ['X-Talixio: 1', 'Content-Type: application/octet-stream', 'X-Forwarded-For: 203.0.113.7']]);
    $out = json_decode((string)curl_exec($ch), true) ?: []; $out['_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $out;
};
// a real recording, cut in three like the browser does
exec('ffmpeg -nostdin -loglevel error -y -f lavfi -i sine=frequency=440:duration=6 -c:a libopus -b:a 32k ' . escapeshellarg("$d/src.webm") . ' 2>&1', $o, $fc);
$audio = (string)@file_get_contents("$d/src.webm") ?: str_repeat('x', 3000);
$parts = str_split($audio, (int)ceil(strlen($audio) / 3));

$r = $web('/api/recordings', ['name' => 'recording-x.webm', 'mime' => 'audio/webm;codecs=opus', 'folder' => '/Inbox']);
$id = $r['recording']['id'] ?? '';
check('recordings: one starts with an id', $r['_code'] === 200 && preg_match('/^[a-f0-9]{16}$/', $id), $r['_raw']);
check('recordings: the first piece is appended', ($piece($id, 0, $parts[0])['next'] ?? null) === 1);
check('recordings: a piece sent twice is acknowledged, not appended twice', ($p = $piece($id, 0, $parts[0]))['_code'] === 200 && $p['next'] === 1 && $p['bytes'] === strlen($parts[0]));
$p = $piece($id, 2, $parts[2]);
check('recordings: a piece sent ahead of a missing one is refused with the one expected', $p['_code'] === 409 && $p['next'] === 1, json_encode($p));
check('recordings: in order again', ($piece($id, 1, $parts[1])['next'] ?? 0) === 2 && ($piece($id, 2, $parts[2])['next'] ?? 0) === 3);
check('recordings: without the header a piece is refused', (function () use ($port, $d, $id) {
    $ch = curl_init("http://127.0.0.1:$port/api/recordings/$id/chunk?seq=3");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'zz', CURLOPT_COOKIEFILE => "$d/ck-a"]);
    curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return $c === 403; })());
check('recordings: signed out, nothing', $piece($id, 3, 'zz', 'nobody')['_code'] === 401);
check('recordings: an unknown recording is said so', $piece('0123456789abcdef', 0, 'zz')['_code'] === 404);

// interrupted: listed to be offered back
$l = $web('/api/recordings')['recordings'] ?? [];
check('recordings: an unfinished one is listed, with its length', count($l) === 1 && $l[0]['id'] === $id && $l[0]['bytes'] === strlen($audio) && ($fc !== 0 || $l[0]['duration'] > 4), json_encode($l));

// finished: a file like any upload, under the name chosen at the end
$f = $web("/api/recordings/$id/finish", ['name' => 'meeting-x.webm']);
check('recordings: finished, it is a file with its whole audio', $f['_code'] === 200 && $f['file']['name'] === 'meeting-x.webm' && $f['file']['size'] === strlen($audio)
    && ($fc !== 0 || ($f['file']['kind'] === 'audio' && $f['file']['duration'] > 4)), $f['_raw']);
$got = $web("/api/files/{$f['file']['id']}")['_raw'];
check('recordings: the file is the pieces in order', $got === $audio);
check('recordings: and it is not offered back any more', ($web('/api/recordings')['recordings'] ?? null) === [] && $web("/api/recordings/$id/finish", [])['_code'] === 404);

// discarded; and one started but never sent anything is not offered
$id2 = $web('/api/recordings', ['name' => 'r.webm', 'mime' => 'audio/webm'])['recording']['id'];
$piece($id2, 0, 'abc');
$id3 = $web('/api/recordings', ['name' => 'r.webm', 'mime' => 'text/html'])['recording'];
check('recordings: a type that is not audio is not kept', $id3['mime'] === 'audio/webm');
check('recordings: an empty one is not offered back', array_column($web('/api/recordings')['recordings'], 'id') === [$id2]);
$web("/api/recordings/$id2/discard", []);
check('recordings: discarded, gone', $web('/api/recordings')['recordings'] === [] && $piece($id2, 1, 'x')['_code'] === 404 && !glob("$d/recordings/$id2*"));
check('recordings: an empty one cannot be finished', $web("/api/recordings/{$id3['id']}/finish", [])['_code'] === 404);
check('recordings: the library is guarded like an upload', $web('/api/recordings', ['name' => 'r.webm', 'folder' => '/FAQ/English'])['_code'] === 400);

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
