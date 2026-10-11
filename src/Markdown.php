<?php
declare(strict_types=1);
namespace Talixio;

/** The small Markdown subset the assistant uses, to HTML (for email). Everything is escaped first. */
final class Markdown
{
    public static function toHtml(string $md): string
    {
        $inline = function (string $t): string {
            $t = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
            $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
            $t = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $t);
            $t = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2">$1</a>', $t);
            return preg_replace('/(^|[\s(])(https?:\/\/[^\s<)]+)/', '$1<a href="$2">$2</a>', $t);
        };
        $out = [];
        foreach (preg_split('/\n{2,}/', trim(str_replace("\r", '', $md))) as $para) {
            $lines = explode("\n", $para);
            if (preg_match('/^\s*[-*•]\s/', $lines[0]) && count(preg_grep('/^\s*[-*•]\s/', $lines)) === count($lines)) {
                $out[] = '<ul>' . implode('', array_map(fn($l) => '<li>' . $inline(preg_replace('/^\s*[-*•]\s+/', '', $l)) . '</li>', $lines)) . '</ul>';
            } elseif (preg_match('/^\s*\d+[.)]\s/', $lines[0]) && count(preg_grep('/^\s*\d+[.)]\s/', $lines)) === count($lines)) {
                $out[] = '<ol>' . implode('', array_map(fn($l) => '<li>' . $inline(preg_replace('/^\s*\d+[.)]\s+/', '', $l)) . '</li>', $lines)) . '</ol>';
            } elseif (preg_match('/^#{1,6}\s+(.*)$/', $lines[0], $m) && count($lines) === 1) {
                $out[] = '<h3>' . $inline($m[1]) . '</h3>';
            } else {
                $out[] = '<p>' . implode('<br>', array_map($inline, $lines)) . '</p>';
            }
        }
        return implode("\n", $out);
    }

    // ---- full documents (library notes and .md files): CommonMark-style blocks + GitHub tables and task lists ----

    public static function document(string $md): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $md));
        $front = self::frontMatter($lines);
        return $front . self::blocks($lines);
    }

    /** A YAML header (--- key: value --- at the very top): shown as a small table of properties, not as a heading. */
    private static function frontMatter(array &$lines): string
    {
        if (trim($lines[0] ?? '') !== '---') return '';
        $end = null;
        for ($i = 1, $n = min(count($lines), 80); $i < $n; $i++) if (in_array(rtrim($lines[$i]), ['---', '...'], true)) { $end = $i; break; }
        if ($end === null) return '';
        $rows = [];
        foreach (array_slice($lines, 1, $end - 1) as $l) {
            if (preg_match('/^([\w][\w .-]{0,40}):\s*(.*)$/u', $l, $m)) $rows[] = [$m[1], $m[2]];
            elseif (trim($l) !== '' && $rows) $rows[count($rows) - 1][1] .= ' ' . trim($l);
            elseif (trim($l) !== '' && !str_starts_with(ltrim($l), '#')) return ''; // not a header after all
        }
        if (!$rows) return '';
        $lines = array_slice($lines, $end + 1);
        $v = fn(string $s) => htmlspecialchars(preg_replace('/^(["\'])(.*)\1$/s', '$2', trim($s)));
        return '<table class="front">' . implode('', array_map(fn($r) => '<tr><th>' . $v($r[0]) . '</th><td>' . $v($r[1]) . '</td></tr>', $rows)) . "</table>\n";
    }

    private const HR = '/^ {0,3}([-*_])( *\1){2,} *$/';
    private const ITEM = '/^( *)([-*+]|\d{1,9}[.)])( +|$)/';
    private const FENCE = '/^ {0,3}(`{3,}|~{3,})\s*([\w+#.-]*)/';

    private static function blocks(array $lines): string
    {
        $out = []; $n = count($lines); $i = 0;
        while ($i < $n) {
            $line = $lines[$i];
            if (trim($line) === '') { $i++; continue; }
            if (preg_match(self::FENCE, $line, $m)) {
                $code = []; $i++;
                while ($i < $n && !preg_match('/^ {0,3}' . preg_quote($m[1][0], '/') . '{' . strlen($m[1]) . ',}\s*$/', $lines[$i])) $code[] = $lines[$i++];
                $i++;
                $out[] = '<pre><code' . ($m[2] !== '' ? ' class="language-' . htmlspecialchars($m[2]) . '"' : '') . '>' . htmlspecialchars(implode("\n", $code)) . '</code></pre>';
                continue;
            }
            if (preg_match('/^ {0,3}(#{1,6})(?:\s+(.*?))?\s*#*\s*$/', $line, $m)) {
                $l = strlen($m[1]);
                $out[] = "<h$l>" . self::inline($m[2] ?? '') . "</h$l>";
                $i++; continue;
            }
            if (preg_match(self::HR, $line)) { $out[] = '<hr>'; $i++; continue; }
            if (preg_match('/^ {0,3}>/', $line)) {
                $q = [];
                while ($i < $n && trim($lines[$i]) !== '' && (preg_match('/^ {0,3}>/', $lines[$i]) || $q)) {
                    if (!preg_match('/^ {0,3}>/', $lines[$i]) && self::startsBlock($lines[$i])) break;
                    $q[] = preg_replace('/^ {0,3}> ?/', '', $lines[$i]); $i++;
                }
                $out[] = '<blockquote>' . self::blocks($q) . '</blockquote>';
                continue;
            }
            if (str_contains($line, '|') && $i + 1 < $n && preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $lines[$i + 1]) && str_contains($lines[$i + 1], '-')) {
                $head = self::cells($line); $aligns = array_map(fn($c) => str_ends_with($c, ':') ? (str_starts_with($c, ':') ? 'center' : 'right') : '', self::cells($lines[$i + 1]));
                $i += 2; $rows = [];
                while ($i < $n && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) $rows[] = self::cells($lines[$i++]);
                $td = fn($tag, $c, $k) => "<$tag" . (!empty($aligns[$k]) ? " style=\"text-align:{$aligns[$k]}\"" : '') . '>' . self::inline($c) . "</$tag>";
                $html = '<div class="scroll"><table><thead><tr>' . implode('', array_map(fn($c, $k) => $td('th', $c, $k), $head, array_keys($head))) . '</tr></thead><tbody>';
                foreach ($rows as $r) { $html .= '<tr>'; for ($k = 0; $k < count($head); $k++) $html .= $td('td', $r[$k] ?? '', $k); $html .= '</tr>'; }
                $out[] = $html . '</tbody></table></div>';
                continue;
            }
            if (preg_match(self::ITEM, $line)) { $out[] = self::listBlock($lines, $i); continue; }
            $p = [];
            while ($i < $n && trim($lines[$i]) !== '') {
                if ($p && preg_match('/^ {0,3}(=+|-+)\s*$/', $lines[$i])) { $p[] = $lines[$i++]; break; } // setext underline
                if ($p && self::startsBlock($lines[$i])) break;
                $p[] = $lines[$i++];
            }
            // setext headings
            if (count($p) >= 2 && preg_match('/^ {0,3}(=+|-+)\s*$/', end($p), $m)) {
                array_pop($p);
                $l = $m[1][0] === '=' ? 1 : 2;
                $out[] = "<h$l>" . self::inline(implode("\n", array_map('trim', $p))) . "</h$l>";
                continue;
            }
            $out[] = '<p>' . self::inline(implode("\n", array_map(fn($l) => ltrim($l), $p))) . '</p>';
        }
        return implode("\n", $out);
    }

    private static function startsBlock(string $line): bool
    {
        return (bool)(preg_match('/^ {0,3}(#{1,6}(\s|$)|>|`{3,}|~{3,})/', $line) || preg_match(self::HR, $line) || preg_match('/^ {0,3}([-*+]|1[.)]) +\S/', $line));
    }

    private static function cells(string $row): array
    {
        $row = trim($row);
        if (str_starts_with($row, '|')) $row = substr($row, 1);
        if (str_ends_with($row, '|') && !str_ends_with($row, '\|')) $row = substr($row, 0, -1);
        return array_map(fn($c) => trim(str_replace('\|', '|', $c)), preg_split('/(?<!\\\\)\|/', $row));
    }

    private static function listBlock(array $lines, int &$i): string
    {
        preg_match(self::ITEM, $lines[$i], $m);
        $indent = strlen($m[1]);
        $ordered = !in_array($m[2], ['-', '*', '+'], true);
        $start = $ordered ? (int)$m[2] : 1;
        $items = []; $loose = false; $n = count($lines);
        while ($i < $n && preg_match(self::ITEM, $lines[$i], $m) && strlen($m[1]) === $indent && $ordered === !in_array($m[2], ['-', '*', '+'], true)
            && !preg_match(self::HR, $lines[$i])) {
            $contentIndent = strlen($m[0]) ?: $indent + strlen($m[2]) + 1;
            $item = [substr($lines[$i], strlen($m[0]))];
            $i++;
            while ($i < $n) {
                $l = $lines[$i];
                if (trim($l) === '') {
                    $next = $lines[$i + 1] ?? '';
                    $nextIndent = strlen($next) - strlen(ltrim($next, ' '));
                    if (trim($next) !== '' && $nextIndent >= $contentIndent) { $item[] = ''; $i++; $loose = true; continue; }
                    if (trim($next) !== '' && preg_match(self::ITEM, $next, $nm) && strlen($nm[1]) === $indent && $ordered === !in_array($nm[2], ['-', '*', '+'], true)) { $loose = true; $i++; }
                    break;
                }
                $lead = strlen($l) - strlen(ltrim($l, ' '));
                if ($lead >= $contentIndent) { $item[] = substr($l, min($lead, $contentIndent)); $i++; continue; }
                if (preg_match(self::ITEM, $l) || self::startsBlock($l)) break;
                if (end($item) !== '') { $item[] = ltrim($l); $i++; continue; } // lazy continuation
                break;
            }
            $items[] = $item;
        }
        $tag = $ordered ? 'ol' : 'ul';
        $html = "<$tag" . ($ordered && $start !== 1 ? " start=\"$start\"" : '') . '>';
        foreach ($items as $item) {
            $task = null;
            if (preg_match('/^\[([ xX])\]\s+/', $item[0], $tm)) { $task = $tm[1] !== ' '; $item[0] = substr($item[0], strlen($tm[0])); }
            $body = self::blocks($item);
            if (!$loose) $body = preg_replace('#^<p>(.*?)</p>(?=\n|$)#s', '$1', $body, 1);
            $html .= $task === null ? "<li>$body</li>" : '<li class="task"><input type="checkbox" disabled' . ($task ? ' checked' : '') . '>' . $body . '</li>';
        }
        return $html . "</$tag>";
    }

    /** Inline Markdown: code, images, links, emphasis, strikethrough, autolinks, hard breaks. Escapes everything else. */
    public static function inline(string $t): string
    {
        $keep = [];
        $hold = function (string $html) use (&$keep) { $keep[] = $html; return "\x01" . (count($keep) - 1) . "\x02"; };
        $url = function (string $u): ?string {
            $u = trim($u, " <>");
            if (preg_match('#^(https?:|mailto:|tel:|\#|/|\./|\.\./)#i', $u) || !preg_match('#^[a-z][a-z0-9+.-]*:#i', $u)) return htmlspecialchars($u, ENT_QUOTES);
            return null; // javascript:, data: and other schemes are dropped
        };
        $t = preg_replace_callback('/(`+)(.+?)\1/s', fn($m) => $hold('<code>' . htmlspecialchars(trim($m[2])) . '</code>'), $t);
        $t = preg_replace_callback('/\\\\([\\\\`*_{}\[\]()#+\-.!|~<>])/', fn($m) => $hold(htmlspecialchars($m[1])), $t);
        $dest = '((?:[^\s()]|\([^\s()]*\))+)';
        $t = preg_replace_callback('/!\[([^\]]*)\]\(\s*' . $dest . '(?:\s+"[^"]*")?\s*\)/', function ($m) use ($hold, $url) {
            $u = preg_match('#^https://#i', $m[2]) ? $url($m[2]) : null;
            return $hold($u ? '<img src="' . $u . '" alt="' . htmlspecialchars($m[1]) . '">' : '[' . htmlspecialchars($m[1] ?: 'image') . ']');
        }, $t);
        $t = preg_replace_callback('/\[([^\]]+)\]\(\s*' . $dest . '(?:\s+"([^"]*)")?\s*\)/', function ($m) use ($hold, $url) {
            $u = $url($m[2]);
            return $u === null ? $m[1] : $hold('<a href="' . $u . '"' . (!empty($m[3]) ? ' title="' . htmlspecialchars($m[3]) . '"' : '') . '>') . $m[1] . $hold('</a>');
        }, $t);
        $t = preg_replace_callback('/<((?:https?:\/\/|mailto:)[^\s>]+)>/i', fn($m) => $hold('<a href="' . htmlspecialchars($m[1]) . '">' . htmlspecialchars($m[1]) . '</a>'), $t);
        $t = preg_replace_callback('/(?<![="\w])(https?:\/\/[^\s<]+[^\s<.,;:!?)\]\'"])/i', fn($m) => $hold('<a href="' . htmlspecialchars($m[1]) . '">' . htmlspecialchars($m[1]) . '</a>'), $t);
        $t = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $t = preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/s', '<strong>$2</strong>', $t);
        $t = preg_replace('/(?<![*\w])\*(?=\S)(.+?)(?<=\S)\*(?!\*)/s', '<em>$1</em>', $t);
        $t = preg_replace('/(?<![_\w])_(?=\S)(.+?)(?<=\S)_(?![_\w])/s', '<em>$1</em>', $t);
        $t = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<s>$1</s>', $t);
        $t = preg_replace('/( {2,}|\\\\)\n/', "<br>\n", $t);
        return preg_replace_callback("/\x01(\d+)\x02/", fn($m) => $keep[(int)$m[1]], $t);
    }
}
