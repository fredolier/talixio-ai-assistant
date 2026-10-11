<?php
// Fake SMTP server for tests: one session (or argv[3] sessions), no TLS, records the transcript to argv[2].
$srv = stream_socket_server('tcp://127.0.0.1:' . $argv[1], $e, $es);
$log = '';
$want = (int)($argv[3] ?? 1); $got = 0;
for ($tries = 0; $tries < $want + 5; $tries++) { // the test's readiness probe connects and leaves: skip such sessions
$c = stream_socket_accept($srv, 10);
if (!$c) break;
$w = function (string $l) use ($c) { fwrite($c, $l . "\r\n"); };
$w('220 fake ESMTP');
$inData = false;
while (($line = fgets($c)) !== false) {
    $log .= $line;
    if ($inData) { if (rtrim($line, "\r\n") === '.') { $inData = false; $w('250 queued'); } continue; }
    $cmd = strtoupper(substr(trim($line), 0, 4));
    match (true) {
        $cmd === 'EHLO' => $w("250-fake\r\n250-AUTH PLAIN LOGIN\r\n250 OK"),
        $cmd === 'AUTH' => $w('235 ok'),
        $cmd === 'MAIL', $cmd === 'RCPT' => $w('250 ok'),
        $cmd === 'DATA' => ($inData = true) && $w('354 go'),
        $cmd === 'QUIT' => $w('221 bye'),
        default => $w('500 ?'),
    };
    if ($cmd === 'QUIT') break;
}
if ($log !== '' && ++$got >= $want) break;
if ($log !== '') file_put_contents($argv[2], $log);
}
file_put_contents($argv[2], $log);
