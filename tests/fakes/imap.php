<?php
// Fake IMAP server for tests: serves the .eml files in argv[2] (uid = position), records STORE/APPEND/SEARCH.
$srv = stream_socket_server('tcp://127.0.0.1:' . $argv[1], $e, $es);
$dir = $argv[2];
for ($conn = 0; $conn < 30; $conn++) {
    $c = @stream_socket_accept($srv, 10);
    if (!$c) break;
    $files = glob("$dir/*.eml"); sort($files);
    fwrite($c, "* OK fake IMAP ready\r\n");
    while (($line = fgets($c)) !== false) {
        [$tag, $cmd] = explode(' ', trim($line), 2) + [1 => ''];
        $up = strtoupper($cmd);
        if (str_starts_with($up, 'LOGIN')) fwrite($c, "$tag OK logged in\r\n");
        elseif (str_starts_with($up, 'SELECT') || str_starts_with($up, 'EXAMINE')) fwrite($c, "* " . count($files) . " EXISTS\r\n$tag OK selected\r\n");
        elseif (str_starts_with($up, 'UID SEARCH')) { file_put_contents("$dir/search.log", $cmd . "\n", FILE_APPEND); fwrite($c, "* SEARCH " . implode(' ', $files ? range(1, count($files)) : []) . "\r\n$tag OK done\r\n"); }
        elseif (preg_match('/^UID FETCH (\d+)/', $up, $m)) {
            $raw = str_replace("\n", "\r\n", str_replace("\r\n", "\n", file_get_contents($files[$m[1] - 1])));
            $flags = (int)$m[1] === 1 ? '\\Seen' : '';
            fwrite($c, "* {$m[1]} FETCH (UID {$m[1]} FLAGS ($flags) BODY[]<0> {" . strlen($raw) . "}\r\n$raw)\r\n$tag OK fetched\r\n");
        } elseif (str_starts_with($up, 'UID STORE')) { file_put_contents("$dir/stored.log", $cmd . "\n", FILE_APPEND); fwrite($c, "$tag OK stored\r\n"); }
        elseif (str_starts_with($up, 'LIST')) fwrite($c, "* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n* LIST (\\HasNoChildren \\Drafts) \"/\" \"Brouillons\"\r\n* LIST (\\Sent) \"/\" \"Envoyés\"\r\n$tag OK listed\r\n");
        elseif (preg_match('/^APPEND "([^"]+)"(?: \(([^)]*)\))? \{(\d+)\}$/i', $cmd, $m)) {
            fwrite($c, "+ go ahead\r\n");
            $data = ''; while (strlen($data) < (int)$m[3]) $data .= fread($c, (int)$m[3] - strlen($data));
            fgets($c); // trailing CRLF
            file_put_contents("$dir/appended.log", "folder={$m[1]} flags={$m[2]}\n$data\n---\n", FILE_APPEND);
            fwrite($c, "$tag OK appended\r\n");
        }
        elseif (str_starts_with($up, 'LOGOUT')) { fwrite($c, "* BYE\r\n$tag OK bye\r\n"); break; }
        else fwrite($c, "$tag BAD unknown\r\n");
    }
    fclose($c);
}
