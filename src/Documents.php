<?php

declare(strict_types=1);

namespace Talixio;

/**
 * Makes documents (PDF, Word, Excel, OpenDocument, HTML) from text the assistant writes, with LibreOffice,
 * and saves them to the library. Everything runs on the server: nothing is sent to an outside service.
 */
final class Documents
{
    // Target format => [the --convert-to value, extension]. HTML and Markdown are written as-is (no LibreOffice).
    private const DOC = ['pdf' => 'pdf', 'docx' => 'docx:MS Word 2007 XML', 'odt' => 'odt'];
    private const SHEET = ['xlsx' => 'xlsx:Calc MS Excel 2007 XML', 'ods' => 'ods'];

    public function __construct(private App $app) {}

    /** @return string[] the formats make() accepts */
    public static function formats(): array { return ['pdf', 'docx', 'odt', 'xlsx', 'ods', 'html', 'md']; }

    public static function needsOffice(string $format): bool { return isset(self::DOC[$format]) || isset(self::SHEET[$format]); }

    /**
     * Makes one document and saves it to the library. For pdf/docx/odt/html, $content is Markdown ($from 'markdown')
     * or HTML ($from 'html'); for xlsx/ods it is CSV, or a Markdown/HTML table. @return int the new file's id.
     */
    public function make(string $content, string $format, string $name, string $folder = Library::INBOX, string $from = 'markdown'): int
    {
        $format = strtolower(trim($format));
        if (!in_array($format, self::formats(), true)) throw new \InvalidArgumentException('format: one of ' . implode(', ', self::formats()));
        if (trim($content) === '') throw new \InvalidArgumentException('content is empty');
        if (self::needsOffice($format) && Files::officeBinary() === null) throw new \RuntimeException('making a ' . strtoupper($format) . ' needs LibreOffice (install.sh --with-office)');
        $base = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', trim($name)) ?: 'document';
        $tmp = sys_get_temp_dir() . '/talixio-doc-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700, true);
        try {
            if ($format === 'md') { $path = "$tmp/$base.md"; file_put_contents($path, $content); $mime = 'text/markdown'; }
            elseif ($format === 'html') { $path = "$tmp/$base.html"; file_put_contents($path, self::htmlDoc($content, $from, $base)); $mime = 'text/html'; }
            elseif (isset(self::SHEET[$format])) {
                file_put_contents("$tmp/in.csv", $from === 'csv' ? $content : self::toCsv($content, $from));
                $path = $this->convert($tmp, 'in.csv', $format, self::SHEET[$format], "$base.$format");
                $mime = '';
            } else {
                file_put_contents("$tmp/in.html", self::htmlDoc($content, $from, $base));
                $path = $this->convert($tmp, 'in.html', $format, self::DOC[$format], "$base.$format");
                $mime = '';
            }
            return $this->app->files()->save($path, basename($path), $mime, $folder, false);
        } finally {
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }

    /** A styled, self-contained HTML page from Markdown or HTML. */
    private static function htmlDoc(string $content, string $from, string $title): string
    {
        if ($from === 'html') {
            return preg_match('/<(!doctype|html)[\s>]/i', $content) ? $content : Preview::page($title, '<div class="doc">' . $content . '</div>');
        }
        return Preview::page($title, '<div class="doc">' . Markdown::document($content) . '</div>');
    }

    /** CSV from a Markdown or HTML table (the first table found), so a sheet can be made from either. */
    private static function toCsv(string $content, string $from): string
    {
        $rows = [];
        if ($from === 'html' || preg_match('/<table[\s>]/i', $content)) {
            if (preg_match('/<table[^>]*>(.*?)<\/table>/is', $content, $tm)
                && preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $tm[1], $trs)) {
                foreach ($trs[1] as $tr) {
                    preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/is', $tr, $cells);
                    $rows[] = array_map(fn($c) => trim(html_entity_decode(strip_tags($c), ENT_QUOTES | ENT_HTML5, 'UTF-8')), $cells[1]);
                }
            }
        } else { // Markdown table: pipe-separated rows, with a |---|---| separator line
            foreach (explode("\n", trim($content)) as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^\|?[\s:|-]+\|?$/', $line) && str_contains($line, '-')) continue;
                $cells = preg_split('/\s*\|\s*/', trim($line, '| '));
                $rows[] = array_map('trim', $cells);
            }
        }
        if (!$rows) throw new \InvalidArgumentException('no table found: give CSV, or a Markdown/HTML table, for a spreadsheet');
        $out = fopen('php://temp', 'r+');
        foreach ($rows as $r) fputcsv($out, $r, ',', '"', '');
        rewind($out);
        return (string)stream_get_contents($out);
    }

    /** Runs LibreOffice once in its own throwaway profile and returns the produced file, renamed to $outName. */
    private function convert(string $tmp, string $inName, string $format, string $filter, string $outName): string
    {
        $bin = Files::officeBinary() ?? throw new \RuntimeException('LibreOffice is not installed');
        mkdir("$tmp/out", 0700, true);
        $cmd = 'HOME=' . escapeshellarg($tmp) . ' timeout 180 ' . escapeshellarg($bin) . ' --headless --norestore --nolockcheck -env:UserInstallation=file://' . $tmp . '/profile'
            . ' --convert-to ' . escapeshellarg($filter) . ' --outdir ' . escapeshellarg("$tmp/out") . ' ' . escapeshellarg("$tmp/$inName") . ' 2>&1';
        $log = (string)shell_exec($cmd);
        $produced = "$tmp/out/" . pathinfo($inName, PATHINFO_FILENAME) . ".$format";
        if (!is_file($produced)) throw new \RuntimeException('LibreOffice could not make the ' . strtoupper($format) . ': ' . mb_substr(trim($log), -300));
        $dest = "$tmp/$outName";
        rename($produced, $dest);
        return $dest;
    }
}
