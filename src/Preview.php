<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Readable previews of library files as standalone HTML pages: Markdown rendered, HTML pages as they
 * look (served sandboxed, scripts off), Word/Excel/PowerPoint/OpenDocument converted with their
 * images, CSV as a table, emails with their headers. Served with a sandbox CSP, never as app pages.
 */
final class Preview
{
    public const MAX_IMAGE = 3_000_000;

    public function __construct(private App $app) {}

    /** Does this file get a rendered page (as opposed to the browser showing it natively)? */
    public static function renders(array $f): bool
    {
        return in_array($f['kind'], ['text', 'office'], true);
    }

    public function render(array $f): string
    {
        $name = strtolower($f['name']);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $raw = fn() => (string)file_get_contents($f['path'], false, null, 0, 20_000_000);
        try {
            return match (true) {
                $f['note'] || in_array($ext, ['md', 'markdown'], true) => self::page($f['name'], '<article class="doc">' . Markdown::document(self::utf8($raw())) . '</article>'),
                in_array($ext, ['html', 'htm'], true) || str_contains($f['mime'], 'html') => self::htmlPage(self::utf8($raw())),
                $ext === 'eml' || $f['mime'] === 'message/rfc822' => $this->email($raw(), $f['name']),
                in_array($ext, ['csv', 'tsv'], true) || $f['mime'] === 'text/csv' => self::page($f['name'], self::csv(self::utf8($raw()), $ext === 'tsv' ? "\t" : null)),
                $ext === 'json' => self::page($f['name'], '<pre class="code">' . self::e(self::prettyJson(self::utf8($raw()))) . '</pre>'),
                $ext === 'docx' => self::page($f['name'], '<article class="doc">' . self::docx($f['path']) . '</article>'),
                $ext === 'xlsx' => self::page($f['name'], self::xlsx($f['path'])),
                $ext === 'pptx' => self::page($f['name'], self::pptx($f['path'])),
                in_array($ext, ['odt', 'ods', 'odp'], true) => self::page($f['name'], '<article class="doc">' . self::odf($f['path']) . '</article>'),
                $f['kind'] === 'text' => self::page($f['name'], '<pre class="code">' . self::e(self::utf8($raw())) . '</pre>'),
                default => self::page($f['name'], '<pre class="code">' . self::e((string)$f['text']) . '</pre>'),
            };
        } catch (\Throwable $e) {
            return self::page($f['name'], '<p class="muted">This file could not be shown (' . self::e($e->getMessage()) . '). Download it to open it.</p>'
                . ($f['text'] ? '<h3>Text</h3><pre class="code">' . self::e((string)$f['text']) . '</pre>' : ''));
        }
    }

    // ---- page shell ----

    public static function page(string $title, string $body): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><base target="_blank">'
            . '<title>' . self::e($title) . '</title><style>' . self::CSS . '</style></head><body>' . $body . '</body></html>';
    }

    private const CSS = <<<'CSS'
:root{color-scheme:light;--ink:#1d2523;--muted:#5b6a66;--line:#d9e2df;--sunk:#f2f5f4;--accent:#0e7c6b}
*{box-sizing:border-box}
body{margin:0;padding:24px clamp(14px,4vw,40px);font:15px/1.6 ui-sans-serif,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;color:var(--ink);background:#fff;overflow-wrap:anywhere}
.doc{max-width:820px;margin:0 auto}
h1,h2,h3,h4,h5,h6{line-height:1.25;margin:1.3em 0 .5em} h1{font-size:1.8em} h2{font-size:1.45em;border-bottom:1px solid var(--line);padding-bottom:.25em} h3{font-size:1.2em}
.doc>:first-child{margin-top:0}
p{margin:.6em 0} a{color:var(--accent)} img{max-width:100%;height:auto}
code{font:.88em ui-monospace,Menlo,Consolas,monospace;background:var(--sunk);padding:.1em .35em;border-radius:4px}
pre{background:var(--sunk);padding:12px 14px;border-radius:8px;overflow-x:auto} pre code{background:none;padding:0}
pre.code{white-space:pre-wrap;font:.86em/1.55 ui-monospace,Menlo,Consolas,monospace;margin:0}
blockquote{margin:.8em 0;padding:.2em 1em;border-left:4px solid var(--line);color:var(--muted)}
hr{border:0;border-top:1px solid var(--line);margin:1.5em 0}
ul,ol{padding-left:1.6em} li{margin:.2em 0} li.task{list-style:none;margin-left:-1.3em} li.task input{margin-right:.5em}
table{border-collapse:collapse;margin:.8em 0;font-size:.92em;max-width:100%}
.scroll{overflow-x:auto;margin:.8em 0} .scroll table{margin:0}
th,td{border:1px solid var(--line);padding:5px 9px;text-align:left;vertical-align:top} th{background:var(--sunk);font-weight:600}
td.num{text-align:right;font-variant-numeric:tabular-nums} td.rowh{background:var(--sunk);color:var(--muted);text-align:right;font-size:.85em}
.sheet-name{font-weight:600;margin:1.4em 0 .4em} .sheet-name:first-child{margin-top:0}
.slide{max-width:880px;margin:0 auto 18px;border:1px solid var(--line);border-radius:10px;padding:22px 28px;box-shadow:0 2px 10px rgba(0,0,0,.05);aspect-ratio:16/9;overflow:auto}
.slide .num{float:right;color:var(--muted);font-size:.8em}
.slide h2{border:0;margin-top:0}
.mail{max-width:820px;margin:0 auto 16px;border-collapse:collapse;font-size:.92em} .mail th{background:none;border:0;color:var(--muted);font-weight:500;padding:2px 12px 2px 0;white-space:nowrap} .mail td{border:0;padding:2px 0}
.muted{color:var(--muted)} u{text-decoration:underline} s{text-decoration:line-through}
CSS;

    public static function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private static function utf8(string $s): string
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) $s = substr($s, 3);
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    /** A saved web page as it looks; served sandboxed without scripts. Links open in a new tab. */
    public static function htmlPage(string $html): string
    {
        $base = '<base target="_blank">';
        if (preg_match('/<head\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) return substr_replace($html, $m[0][0] . $base, $m[0][1], strlen($m[0][0]));
        return '<!doctype html><meta charset="utf-8">' . $base . $html;
    }

    private function email(string $raw, string $name): string
    {
        $m = Mime::parse($raw); $h = $m['headers'];
        $rows = '';
        foreach (['from' => 'From', 'to' => 'To', 'cc' => 'Cc', 'date' => 'Date', 'subject' => 'Subject'] as $k => $label) {
            if (!empty($h[$k])) $rows .= '<tr><th>' . $label . '</th><td>' . self::e(Mime::decodeHeader($h[$k])) . '</td></tr>';
        }
        $html = Mime::htmlBody($raw);
        $body = $html !== ''
            ? preg_replace('#<(script|style|head|title|meta|link|base)\b.*?(</\1>|>)#is', '', $html) // keep the message's layout, drop its page-level parts
            : '<div class="doc"><pre class="code">' . self::e($m['text']) . '</pre></div>';
        return self::page($name, '<table class="mail">' . $rows . '</table><hr>' . $body);
    }

    private static function prettyJson(string $s): string
    {
        $d = json_decode($s);
        return json_last_error() === JSON_ERROR_NONE ? (string)json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $s;
    }

    public static function csv(string $text, ?string $sep = null): string
    {
        $first = strtok($text, "\n") ?: '';
        $sep ??= substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        $fh = fopen('php://memory', 'r+'); fwrite($fh, $text); rewind($fh);
        $rows = [];
        while (count($rows) < 2000 && ($r = fgetcsv($fh, null, $sep, '"', '')) !== false) if ($r !== [null]) $rows[] = $r;
        $more = !feof($fh);
        fclose($fh);
        return self::table($rows, true) . ($more ? '<p class="muted">First 2,000 rows shown. Download the file to see everything.</p>' : '');
    }

    /** @param array<array<string|null>> $rows */
    private static function table(array $rows, bool $header, bool $rowNumbers = false): string
    {
        if (!$rows) return '<p class="muted">(empty)</p>';
        $w = max(array_map('count', $rows));
        $out = '<div class="scroll"><table>';
        foreach ($rows as $i => $r) {
            $tag = $header && $i === 0 ? 'th' : 'td';
            $out .= '<tr>' . ($rowNumbers ? '<td class="rowh">' . ($i + 1) . '</td>' : '');
            for ($c = 0; $c < $w; $c++) {
                $v = (string)($r[$c] ?? '');
                $num = $tag === 'td' && $v !== '' && is_numeric(str_replace([' ', ','], ['', '.'], $v));
                $out .= "<$tag" . ($num ? ' class="num"' : '') . '>' . self::e($v) . "</$tag>";
            }
            $out .= '</tr>';
        }
        return $out . '</table></div>';
    }

    // ---- Office Open XML ----

    private static function zip(string $path): \ZipArchive
    {
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('previews of Office files need php-zip');
        if (!class_exists(\DOMDocument::class)) throw new \RuntimeException('previews of Office files need php-xml');
        $z = new \ZipArchive();
        if ($z->open($path) !== true) throw new \RuntimeException('not a valid Office file');
        return $z;
    }

    private static function dom(\ZipArchive $z, string $name): ?\DOMXPath
    {
        $xml = $z->getFromName($name);
        if ($xml === false) return null;
        $d = new \DOMDocument();
        if (!@$d->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) return null;
        $x = new \DOMXPath($d);
        foreach (['w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
            'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'p' => 'http://schemas.openxmlformats.org/presentationml/2006/main',
            's' => 'http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'rel' => 'http://schemas.openxmlformats.org/package/2006/relationships',
            'text' => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'table' => 'urn:oasis:names:tc:opendocument:xmlns:table:1.0',
            'office' => 'urn:oasis:names:tc:opendocument:xmlns:office:1.0', 'draw' => 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0',
            'xlink' => 'http://www.w3.org/1999/xlink'] as $p => $ns) $x->registerNamespace($p, $ns);
        return $x;
    }

    /** id => target path inside the zip, for a part's relationships. */
    private static function rels(\ZipArchive $z, string $part): array
    {
        $relsName = dirname($part) . '/_rels/' . basename($part) . '.rels';
        $x = self::dom($z, $relsName);
        $out = [];
        if (!$x) return $out;
        foreach ($x->query('//rel:Relationship') as $r) {
            /** @var \DOMElement $r */
            $t = $r->getAttribute('Target');
            $out[$r->getAttribute('Id')] = $r->getAttribute('TargetMode') === 'External' ? $t : self::resolve(dirname($part), $t);
        }
        return $out;
    }

    private static function resolve(string $dir, string $target): string
    {
        if (str_starts_with($target, '/')) return ltrim($target, '/');
        $parts = [];
        foreach (explode('/', "$dir/$target") as $p) {
            if ($p === '..') array_pop($parts); elseif ($p !== '.' && $p !== '') $parts[] = $p;
        }
        return implode('/', $parts);
    }

    private static function imageData(\ZipArchive $z, string $name): ?string
    {
        $st = $z->statName($name);
        if (!$st || $st['size'] > self::MAX_IMAGE) return null;
        $mime = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) { 'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', default => null };
        $data = $mime ? $z->getFromName($name) : false;
        return $data === false ? null : "data:$mime;base64," . base64_encode($data);
    }

    public static function docx(string $path): string
    {
        $z = self::zip($path);
        $x = self::dom($z, 'word/document.xml') ?? throw new \RuntimeException('no document in this file');
        $rels = self::rels($z, 'word/document.xml');
        $headings = [];
        if ($sx = self::dom($z, 'word/styles.xml')) {
            foreach ($sx->query('//w:style[@w:type="paragraph"]') as $s) {
                /** @var \DOMElement $s */
                $name = strtolower((string)$sx->evaluate('string(w:name/@w:val)', $s));
                $lvl = (string)$sx->evaluate('string(w:pPr/w:outlineLvl/@w:val)', $s);
                if (preg_match('/^(heading|titre|überschrift|titolo|título)\s*(\d)$/u', $name, $m)) $headings[$s->getAttribute('w:styleId')] = min(6, (int)$m[2]);
                elseif ($name === 'title') $headings[$s->getAttribute('w:styleId')] = 1;
                elseif ($lvl !== '') $headings[$s->getAttribute('w:styleId')] = min(6, (int)$lvl + 1);
            }
        }
        $body = $x->query('//w:body')->item(0);
        $out = self::docxBlocks($x, $body, $rels, $z, $headings);
        $z->close();
        return $out;
    }

    private static function docxBlocks(\DOMXPath $x, \DOMNode $parent, array $rels, \ZipArchive $z, array $headings): string
    {
        $out = ''; $list = null;
        $close = function () use (&$out, &$list) { if ($list) { $out .= "</$list>"; $list = null; } };
        foreach ($parent->childNodes as $n) {
            if (!$n instanceof \DOMElement) continue;
            if ($n->localName === 'p') {
                $style = (string)$x->evaluate('string(w:pPr/w:pStyle/@w:val)', $n);
                $content = self::docxRuns($x, $n, $rels, $z);
                $isList = $x->query('w:pPr/w:numPr', $n)->length > 0 || stripos($style, 'list') !== false;
                if ($isList && trim(strip_tags($content, '<img>')) !== '') {
                    $fmt = (string)$x->evaluate('string(w:pPr/w:numPr/w:numId/@w:val)', $n);
                    $tag = stripos($style, 'number') !== false ? 'ol' : 'ul';
                    if ($list !== $tag) { $close(); $out .= "<$tag>"; $list = $tag; }
                    $out .= '<li>' . $content . '</li>';
                    continue;
                }
                $close();
                $h = $headings[$style] ?? (preg_match('/^(Heading|Titre)(\d)$/i', $style, $m) ? (int)$m[2] : (strcasecmp($style, 'Title') === 0 ? 1 : 0));
                $align = (string)$x->evaluate('string(w:pPr/w:jc/@w:val)', $n);
                $attr = in_array($align, ['center', 'right'], true) ? " style=\"text-align:$align\"" : '';
                if (trim(strip_tags($content, '<img>')) === '') { $out .= '<p>&nbsp;</p>'; continue; }
                $out .= $h ? "<h$h$attr>$content</h$h>" : "<p$attr>$content</p>";
            } elseif ($n->localName === 'tbl') {
                $close();
                $out .= '<div class="scroll"><table>';
                foreach ($x->query('w:tr', $n) as $tr) {
                    $out .= '<tr>';
                    foreach ($x->query('w:tc', $tr) as $tc) {
                        $span = (int)$x->evaluate('string(w:tcPr/w:gridSpan/@w:val)', $tc);
                        $out .= '<td' . ($span > 1 ? " colspan=\"$span\"" : '') . '>' . self::docxBlocks($x, $tc, $rels, $z, $headings) . '</td>';
                    }
                    $out .= '</tr>';
                }
                $out .= '</table></div>';
            } elseif ($n->localName === 'sdt') {
                $close();
                foreach ($x->query('w:sdtContent', $n) as $c) $out .= self::docxBlocks($x, $c, $rels, $z, $headings);
            }
        }
        $close();
        return $out;
    }

    private static function docxRuns(\DOMXPath $x, \DOMElement $p, array $rels, \ZipArchive $z): string
    {
        $out = '';
        foreach ($x->query('.//w:r | .//w:hyperlink', $p) as $r) {
            /** @var \DOMElement $r */
            if ($r->localName === 'hyperlink') continue; // its runs are visited on their own
            $t = '';
            foreach ($r->childNodes as $c) {
                if (!$c instanceof \DOMElement) continue;
                $t .= match ($c->localName) {
                    't' => self::e($c->textContent), 'tab' => ' &emsp;', 'br', 'cr' => '<br>', 'noBreakHyphen' => '-',
                    'drawing', 'pict' => self::docxImages($x, $c, $rels, $z),
                    default => '',
                };
            }
            if ($t === '') continue;
            $on = fn(string $tag) => $x->query("w:rPr/w:$tag", $r)->length > 0 && !in_array((string)$x->evaluate("string(w:rPr/w:$tag/@w:val)", $r), ['0', 'false', 'none'], true);
            if ($on('b')) $t = "<strong>$t</strong>";
            if ($on('i')) $t = "<em>$t</em>";
            if ($on('u')) $t = "<u>$t</u>";
            if ($on('strike')) $t = "<s>$t</s>";
            $link = $r->parentNode instanceof \DOMElement && $r->parentNode->localName === 'hyperlink' ? ($rels[$r->parentNode->getAttribute('r:id')] ?? '') : '';
            if ($link !== '' && preg_match('#^(https?:|mailto:)#i', $link)) $t = '<a href="' . self::e($link) . '">' . $t . '</a>';
            $out .= $t;
        }
        return $out;
    }

    private static function docxImages(\DOMXPath $x, \DOMElement $node, array $rels, \ZipArchive $z): string
    {
        $out = '';
        foreach ($x->query('.//a:blip/@r:embed | .//*[local-name()="imagedata"]/@r:id', $node) as $id) {
            $src = isset($rels[$id->nodeValue]) ? self::imageData($z, $rels[$id->nodeValue]) : null;
            if ($src) $out .= '<img src="' . $src . '" alt="">';
        }
        return $out;
    }

    public static function xlsx(string $path): string
    {
        $z = self::zip($path);
        $shared = [];
        if ($sx = self::dom($z, 'xl/sharedStrings.xml')) {
            foreach ($sx->query('//s:si') as $si) $shared[] = implode('', array_map(fn($t) => $t->textContent, iterator_to_array($sx->query('.//s:t', $si))));
        }
        $sheets = [];
        $wb = self::dom($z, 'xl/workbook.xml');
        $rels = self::rels($z, 'xl/workbook.xml');
        if ($wb) foreach ($wb->query('//s:sheets/s:sheet') as $s) {
            /** @var \DOMElement $s */
            $target = $rels[$s->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')] ?? null;
            if ($target) $sheets[] = [$s->getAttribute('name'), $target];
        }
        if (!$sheets) for ($i = 1; $z->locateName("xl/worksheets/sheet$i.xml") !== false; $i++) $sheets[] = ["Sheet $i", "xl/worksheets/sheet$i.xml"];
        $out = '';
        foreach (array_slice($sheets, 0, 30) as [$name, $part]) {
            $x = self::dom($z, $part);
            if (!$x) continue;
            $grid = []; $maxCol = 0; $n = 0;
            foreach ($x->query('//s:sheetData/s:row') as $row) {
                if (++$n > 1000) break;
                /** @var \DOMElement $row */
                $ri = (int)$row->getAttribute('r') ?: $n;
                $ci = 0;
                foreach ($x->query('s:c', $row) as $c) {
                    /** @var \DOMElement $c */
                    $ref = $c->getAttribute('r');
                    $ci = $ref !== '' ? self::colIndex($ref) : $ci + 1;
                    if ($ci > 60) continue;
                    $type = $c->getAttribute('t');
                    $v = (string)$x->evaluate('string(s:v)', $c);
                    $val = match ($type) {
                        's' => $shared[(int)$v] ?? '',
                        'inlineStr' => (string)$x->evaluate('string(s:is)', $c),
                        'b' => $v === '1' ? 'TRUE' : 'FALSE',
                        default => is_numeric($v) && str_contains($v, '.') && strlen($v) > 12 ? (string)round((float)$v, 6) : $v,
                    };
                    if ($val === '') continue;
                    $grid[$ri][$ci] = $val;
                    $maxCol = max($maxCol, $ci);
                }
            }
            $out .= '<div class="sheet-name">' . self::e($name) . '</div>';
            if (!$grid) { $out .= '<p class="muted">(empty)</p>'; continue; }
            ksort($grid);
            $rows = [];
            $minRow = array_key_first($grid); $maxRow = array_key_last($grid);
            for ($r = $minRow; $r <= $maxRow; $r++) { $line = []; for ($c = 1; $c <= $maxCol; $c++) $line[] = $grid[$r][$c] ?? ''; $rows[] = $line; }
            $out .= self::table($rows, false);
            if ($n > 1000) $out .= '<p class="muted">First 1,000 rows shown.</p>';
        }
        $z->close();
        return $out ?: '<p class="muted">(empty workbook)</p>';
    }

    private static function colIndex(string $ref): int
    {
        preg_match('/^[A-Z]+/', strtoupper($ref), $m);
        $n = 0;
        foreach (str_split($m[0] ?? 'A') as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n;
    }

    public static function pptx(string $path): string
    {
        $z = self::zip($path);
        $slides = [];
        if (($pres = self::dom($z, 'ppt/presentation.xml')) && ($rels = self::rels($z, 'ppt/presentation.xml'))) {
            foreach ($pres->query('//p:sldIdLst/p:sldId') as $s) {
                /** @var \DOMElement $s */
                $t = $rels[$s->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')] ?? null;
                if ($t) $slides[] = $t;
            }
        }
        if (!$slides) for ($i = 1; $z->locateName("ppt/slides/slide$i.xml") !== false; $i++) $slides[] = "ppt/slides/slide$i.xml";
        $out = '';
        foreach (array_slice($slides, 0, 200) as $i => $part) {
            $x = self::dom($z, $part);
            if (!$x) continue;
            $rels = self::rels($z, $part);
            $html = '';
            foreach ($x->query('//p:cSld/p:spTree/*') as $shape) $html .= self::pptxShape($x, $shape, $rels, $z);
            $out .= '<section class="slide"><span class="num">' . ($i + 1) . '</span>' . ($html ?: '<p class="muted">(no text)</p>') . '</section>';
        }
        $z->close();
        return $out ?: '<p class="muted">(no slides)</p>';
    }

    private static function pptxShape(\DOMXPath $x, \DOMElement $shape, array $rels, \ZipArchive $z): string
    {
        if ($shape->localName === 'grpSp') { $o = ''; foreach ($x->query('p:sp | p:pic | p:grpSp | p:graphicFrame', $shape) as $s) $o .= self::pptxShape($x, $s, $rels, $z); return $o; }
        if ($shape->localName === 'pic') {
            $id = (string)$x->evaluate('string(.//a:blip/@r:embed)', $shape);
            $src = isset($rels[$id]) ? self::imageData($z, $rels[$id]) : null;
            return $src ? '<p><img src="' . $src . '" alt=""></p>' : '';
        }
        if ($shape->localName === 'graphicFrame') {
            $rows = [];
            foreach ($x->query('.//a:tbl/a:tr', $shape) as $tr) $rows[] = array_map(fn($tc) => trim($tc->textContent), iterator_to_array($x->query('a:tc', $tr)));
            return $rows ? self::table($rows, true) : '';
        }
        if ($shape->localName !== 'sp') return '';
        $ph = (string)$x->evaluate('string(.//p:nvPr/p:ph/@type)', $shape);
        $paras = [];
        foreach ($x->query('.//p:txBody/a:p', $shape) as $p) {
            $t = '';
            foreach ($x->query('a:r | a:br | a:fld', $p) as $r) {
                /** @var \DOMElement $r */
                if ($r->localName === 'br') { $t .= '<br>'; continue; }
                $s = self::e((string)$x->evaluate('string(a:t)', $r));
                if ($x->evaluate('string(a:rPr/@b)', $r) === '1') $s = "<strong>$s</strong>";
                if ($x->evaluate('string(a:rPr/@i)', $r) === '1') $s = "<em>$s</em>";
                $t .= $s;
            }
            if (trim(strip_tags($t)) !== '') $paras[] = [$t, (int)$x->evaluate('string(a:pPr/@lvl)', $p)];
        }
        if (!$paras) return '';
        if (in_array($ph, ['title', 'ctrTitle'], true)) return '<h2>' . implode('<br>', array_column($paras, 0)) . '</h2>';
        if ($ph === 'subTitle') return '<p class="muted">' . implode('<br>', array_column($paras, 0)) . '</p>';
        if (count($paras) === 1) return '<p>' . $paras[0][0] . '</p>';
        return '<ul>' . implode('', array_map(fn($p) => '<li' . ($p[1] ? ' style="margin-left:' . (1.4 * $p[1]) . 'em"' : '') . '>' . $p[0] . '</li>', $paras)) . '</ul>';
    }

    // ---- OpenDocument ----

    public static function odf(string $path): string
    {
        $z = self::zip($path);
        $x = self::dom($z, 'content.xml') ?? throw new \RuntimeException('no content in this file');
        $body = $x->query('//office:body/*')->item(0);
        $out = '';
        if ($body && $body->localName === 'presentation') {
            $i = 0;
            foreach ($x->query('draw:page', $body) as $page) {
                $out .= '<section class="slide"><span class="num">' . (++$i) . '</span>' . self::odfBlocks($x, $page, $z) . '</section>';
            }
        } elseif ($body) {
            $out = self::odfBlocks($x, $body, $z);
        }
        $z->close();
        return $out ?: '<p class="muted">(empty)</p>';
    }

    private static function odfBlocks(\DOMXPath $x, \DOMNode $parent, \ZipArchive $z): string
    {
        $out = '';
        foreach ($parent->childNodes as $n) {
            if (!$n instanceof \DOMElement) continue;
            switch ($n->localName) {
                case 'h':
                    $l = max(1, min(6, (int)$n->getAttribute('text:outline-level') ?: 1));
                    $out .= "<h$l>" . self::odfInline($n) . "</h$l>";
                    break;
                case 'p':
                    $t = self::odfInline($n);
                    $out .= trim(strip_tags($t, '<img>')) === '' ? '' : "<p>$t</p>";
                    foreach ($x->query('.//draw:image/@xlink:href', $n) as $href) if ($src = self::imageData($z, $href->nodeValue)) $out .= '<p><img src="' . $src . '" alt=""></p>';
                    break;
                case 'list':
                    $out .= '<ul>';
                    foreach ($x->query('text:list-item', $n) as $li) $out .= '<li>' . preg_replace('#^<p>(.*)</p>$#s', '$1', self::odfBlocks($x, $li, $z)) . '</li>';
                    $out .= '</ul>';
                    break;
                case 'table':
                    $rows = [];
                    foreach ($x->query('.//table:table-row', $n) as $tr) {
                        if (count($rows) >= 1000) break;
                        $cells = [];
                        foreach ($x->query('table:table-cell | table:covered-table-cell', $tr) as $tc) {
                            /** @var \DOMElement $tc */
                            $rep = min(50, max(1, (int)$tc->getAttribute('table:number-columns-repeated')));
                            for ($k = 0; $k < $rep; $k++) $cells[] = trim(implode("\n", array_map(fn($p) => $p->textContent, iterator_to_array($x->query('text:p', $tc)))));
                        }
                        while ($cells && end($cells) === '') array_pop($cells);
                        $rows[] = $cells;
                    }
                    while ($rows && end($rows) === []) array_pop($rows);
                    if ($n->getAttribute('table:name') !== '' && $parent->localName === 'spreadsheet') $out .= '<div class="sheet-name">' . self::e($n->getAttribute('table:name')) . '</div>';
                    $out .= self::table($rows, false);
                    break;
                case 'frame': case 'custom-shape': case 'text-box': case 'section':
                    $out .= self::odfBlocks($x, $n, $z);
                    if ($n->localName === 'frame') foreach ($x->query('draw:image/@xlink:href', $n) as $href) if ($src = self::imageData($z, $href->nodeValue)) $out .= '<p><img src="' . $src . '" alt=""></p>';
                    break;
            }
        }
        return $out;
    }

    private static function odfInline(\DOMElement $el): string
    {
        $out = '';
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMText) { $out .= self::e($c->textContent); continue; }
            if (!$c instanceof \DOMElement) continue;
            $out .= match ($c->localName) {
                's' => str_repeat(' ', max(1, (int)$c->getAttribute('text:c'))), 'tab' => ' &emsp;', 'line-break' => '<br>',
                'a' => preg_match('#^(https?:|mailto:)#i', $c->getAttribute('xlink:href')) ? '<a href="' . self::e($c->getAttribute('xlink:href')) . '">' . self::odfInline($c) . '</a>' : self::odfInline($c),
                'span' => self::odfInline($c),
                default => '',
            };
        }
        return $out;
    }
}
