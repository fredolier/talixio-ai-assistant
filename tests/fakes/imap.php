<?php
// Fake IMAP server for tests: serves the .eml files in argv[2] as unseen messages; records STORE commands.
$srv = stream_socket_server('tcp://127.0.0.1:' . $argv[1], $e, $es);
$files = glob($argv[2] . '/*.eml'); sort($files);
for ($conn = 0; $conn < 10; $conn++) {
    $c = @stream_socket_accept($srv, 10);
    if (!$c) break;
    fwrite($c, "* OK fake IMAP ready\r\n");
    while (($line = fgets($c)) !== false) {
        [$tag, $cmd] = explode(' ', trim($line), 2) + [1 => ''];
        $up = strtoupper($cmd);
        if (str_starts_with($up, 'LOGIN')) fwrite($c, "$tag OK logged in\r\n");
        elseif (str_starts_with($up, 'SELECT')) fwrite($c, "* " . count($files) . " EXISTS\r\n$tag OK [READ-WRITE] selected\r\n");
        elseif (str_starts_with($up, 'UID SEARCH')) fwrite($c, "* SEARCH " . implode(' ', range(1, count($files))) . "\r\n$tag OK done\r\n");
        elseif (preg_match('/^UID FETCH (\d+)/', $up, $m)) {
            $raw = str_replace("\n", "\r\n", str_replace("\r\n", "\n", file_get_contents($files[$m[1] - 1])));
            fwrite($c, "* {$m[1]} FETCH (UID {$m[1]} BODY[] {" . strlen($raw) . "}\r\n$raw)\r\n$tag OK fetched\r\n");
        } elseif (str_starts_with($up, 'UID STORE')) { file_put_contents($argv[2] . '/stored.log', $cmd . "\n", FILE_APPEND); fwrite($c, "$tag OK stored\r\n"); }
        elseif (str_starts_with($up, 'LOGOUT')) { fwrite($c, "* BYE\r\n$tag OK bye\r\n"); break; }
        else fwrite($c, "$tag BAD unknown\r\n");
    }
    fclose($c);
}
