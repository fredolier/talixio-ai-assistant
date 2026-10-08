<?php
declare(strict_types=1);
namespace Talixio;

/** Just enough MIME parsing to read an incoming email: headers, the best text body, without quoted replies. */
final class Mime
{
    /** @return array{headers:array<string,string>,text:string} */
    public static function parse(string $raw): array
    {
        [$head, $body] = self::split($raw);
        $headers = self::headers($head);
        return ['headers' => $headers, 'text' => self::text($headers, $body)];
    }

    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        return $pos === false ? [$raw, ''] : [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    /** @return array<string,string> lowercase names; repeated headers joined with "\n" */
    public static function headers(string $head): array
    {
        $out = [];
        foreach (preg_split('/\n(?![ \t])/', $head) as $line) {
            if (!str_contains($line, ':')) continue;
            [$k, $v] = explode(':', $line, 2);
            $k = strtolower(trim($k));
            $v = trim(preg_replace('/\n[ \t]+/', ' ', $v));
            $out[$k] = isset($out[$k]) ? $out[$k] . "\n" . $v : $v;
        }
        return $out;
    }

    public static function decodeHeader(string $v): string
    {
        return mb_decode_mimeheader($v);
    }

    private static function param(string $header, string $name): string
    {
        return preg_match('/' . $name . '\s*=\s*"?([^";]+)"?/i', $header, $m) ? trim($m[1]) : '';
    }

    private static function text(array $headers, string $body): string
    {
        $type = strtolower($headers['content-type'] ?? 'text/plain');
        if (str_starts_with($type, 'multipart/')) {
            $b = self::param($headers['content-type'], 'boundary');
            if ($b === '') return '';
            $plain = $html = '';
            foreach (array_slice(explode("--$b", $body), 1) as $part) {
                if (str_starts_with($part, '--')) break;
                [$ph, $pb] = self::split(ltrim($part, "\n"));
                $phs = self::headers($ph);
                if (str_contains(strtolower($phs['content-disposition'] ?? ''), 'attachment')) continue;
                $pt = strtolower($phs['content-type'] ?? 'text/plain');
                if (str_starts_with($pt, 'multipart/')) { $t = self::text($phs, $pb); if ($t !== '' && $plain === '') $plain = $t; }
                elseif (str_starts_with($pt, 'text/plain') && $plain === '') $plain = self::decode($phs, $pb);
                elseif (str_starts_with($pt, 'text/html') && $html === '') $html = self::decode($phs, $pb);
            }
            return $plain !== '' ? $plain : Http::htmlToText($html);
        }
        $t = self::decode($headers, $body);
        return str_starts_with($type, 'text/html') ? Http::htmlToText($t) : $t;
    }

    private static function decode(array $h, string $body): string
    {
        $enc = strtolower(trim($h['content-transfer-encoding'] ?? ''));
        $body = match ($enc) { 'base64' => (string)base64_decode($body), 'quoted-printable' => quoted_printable_decode($body), default => $body };
        $cs = strtoupper(self::param($h['content-type'] ?? '', 'charset') ?: 'UTF-8');
        if ($cs !== 'UTF-8' && $cs !== 'US-ASCII') $body = (string)@mb_convert_encoding($body, 'UTF-8', $cs);
        return trim(str_replace("\r", '', $body));
    }

    /** Remove quoted earlier messages and signatures from a reply. */
    public static function stripReply(string $text): string
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(On .{5,200} wrote:|Le .{5,200} a écrit\s?:|-{2,} ?Original Message ?-{2,}|-{2,} ?Message d\'origine ?-{2,}|From: .+@.+|De : .+@.+)$/i', trim($line))) break;
            if ($line === '-- ') break; // signature delimiter
            if (str_starts_with(ltrim($line), '>')) continue;
            $out[] = $line;
        }
        return trim(implode("\n", $out));
    }
}
