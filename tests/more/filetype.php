<?php
declare(strict_types=1);
// Audit S8: what a file is served as. A "PDF" whose sender declared a script or web page type, or whose name ends in .pdf
// over other content, must never reach the browser as that type.
require_once __DIR__ . '/../helpers.php';

$d = test_dir('filetype');
$web = web_app($d, ['TALIXIO_WEB_PASSWORD' => 'correct horse battery']);
$port = (int)(new ReflectionFunction($web))->getStaticVariables()['port'];
check('filetype: sign in', $web('/api/login', ['password' => 'correct horse battery'])['_code'] === 200);
$upload = function (string $content, string $name, string $type) use ($port, $d): int {
    $t = tap_tmp($content);
    $ch = curl_init("http://127.0.0.1:$port/api/files");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEFILE => "$d/ck-a", CURLOPT_COOKIEJAR => "$d/ck-a",
        CURLOPT_HTTPHEADER => ['X-Talixio: 1', 'X-Forwarded-For: 203.0.113.7'], CURLOPT_POSTFIELDS => ['file' => new CURLFile($t, $type, $name), 'folder' => '/Inbox']]);
    $r = json_decode((string)curl_exec($ch), true); curl_close($ch); @unlink($t);
    return (int)($r['id'] ?? $r['file']['id'] ?? 0);
};
$get = function (int $id) use ($port, $d): array {
    $h = [];
    $ch = curl_init("http://127.0.0.1:$port/api/files/$id");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => "$d/ck-a", CURLOPT_HTTPHEADER => ['X-Forwarded-For: 203.0.113.7'],
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$h) { if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $h[strtolower(trim($k))] = trim($v); } return strlen($l); }]);
    curl_exec($ch); curl_close($ch);
    return $h;
};
$safe = fn(array $h) => preg_match('#^application/(pdf|octet-stream)#', $h['content-type'] ?? '') || str_starts_with($h['content-disposition'] ?? '', 'attachment');
$fake = "/*%PDF-1.4*/ document.title = 'pwned';\n%%EOF\n";
foreach (['text/javascript', 'text/HTML', 'TEXT/html', 'application/pdf'] as $type) {
    $id = $upload($fake, 'invoice.pdf', $type);
    $h = $get($id);
    check("filetype: a fake PDF declared $type is served as a PDF or downloaded", $id > 0 && $safe($h) && !preg_match('#javascript|html#i', $h['content-type'] ?? ''), json_encode([$id, $h['content-type'] ?? '', $h['content-disposition'] ?? '']));
}
$id = $upload("<svg xmlns='http://www.w3.org/2000/svg' onload='alert(1)'/>", 'photo.png', 'image/png');
$h = $get($id);
check('filetype: an SVG named .png and declared image/png is downloaded', str_starts_with($h['content-disposition'] ?? '', 'attachment'), json_encode($h));
$id = $upload("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", 'real.pdf', 'application/pdf');
$h = $get($id);
check('filetype: a real PDF still opens inline as a PDF', ($h['content-type'] ?? '') === 'application/pdf' && str_starts_with($h['content-disposition'] ?? '', 'inline'), json_encode($h));
$id = $upload("hello\n", 'note.txt', 'text/html');
check('filetype: a text file declared as a web page is stored as text', $id > 0 && !str_contains($get($id)['content-type'] ?? '', 'html'), json_encode($get($id)));
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) tests_done();
