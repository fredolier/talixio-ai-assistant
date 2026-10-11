<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The HTML of the emails Talixio sends. Mail apps ignore most CSS, so every style is inline and the
 * layout is made of tables (Outlook). Two looks:
 * - page(): the assistant's own messages (scheduled jobs, briefings, notifications, chat by email),
 *   a card with a title, the space and a button that opens the app;
 * - plain(): mail written on the user's behalf, formatted but with no Talixio frame.
 */
final class MailTemplate
{
    public const ACCENT = '#0e7c6b';
    private const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    private const INK = '#14201d';
    private const MUTED = '#5f6f6a';
    private const LINE = '#e2e8e6';

    /**
     * @param array{title?:string, app?:string, space?:string, color?:string, url?:string, button?:string, footer?:string, base?:string} $o
     * base: the app's address, to turn relative links (#lib/…, /fred/…) into ones that work from a mail app.
     */
    public static function page(string $markdown, array $o = []): string
    {
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $accent = preg_match('/^#[0-9a-f]{6}$/i', $o['color'] ?? '') ? strtolower($o['color']) : self::ACCENT;
        $title = trim($o['title'] ?? '');
        $md = self::dropTitle($markdown, $title);
        $body = self::body($md, $accent, $o['base'] ?? '');
        $label = implode(' · ', array_filter([trim($o['app'] ?? ''), trim($o['space'] ?? '')]));
        $url = self::link($o['url'] ?? '', $o['base'] ?? '');
        $button = $url !== '' && preg_match('#^https?://#i', $url)
            ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:28px 0 4px"><tr><td style="border-radius:8px;background:' . $accent . '">'
              . '<a href="' . $e($url) . '" style="display:inline-block;padding:11px 22px;font-family:' . self::FONT . ';font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px">'
              . $e($o['button'] ?? 'Open') . '</a></td></tr></table>'
            : '';
        $head = $label === '' ? '' : '<tr><td style="padding:0 6px 12px;font-family:' . self::FONT . ';font-size:13px;font-weight:600;color:' . self::MUTED . '">'
            . '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' . $accent . ';margin-right:7px;vertical-align:1px"></span>' . $e($label) . '</td></tr>';
        $h1 = $title === '' ? '' : '<h1 style="margin:0 0 18px;font-family:' . self::FONT . ';font-size:22px;line-height:1.3;font-weight:700;color:' . self::INK . '">' . $e($title) . '</h1>';
        $footer = trim($o['footer'] ?? '');
        return self::document($title, self::preheader($md),
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f5f4"><tr><td align="center" style="padding:28px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:640px">'
            . $head
            . '<tr><td class="card" style="background:#ffffff;border:1px solid ' . self::LINE . ';border-top:4px solid ' . $accent . ';border-radius:12px;padding:30px 32px">'
            . $h1 . $body . $button . '</td></tr>'
            . ($footer === '' ? '' : '<tr><td style="padding:16px 6px 0;font-family:' . self::FONT . ';font-size:12px;line-height:1.5;color:#8a9893;text-align:center">' . $e($footer) . '</td></tr>')
            . '</table></td></tr></table>');
    }

    /** Mail sent as the user: their text, formatted, with nothing added. */
    public static function plain(string $markdown, string $base = ''): string
    {
        return self::document('', '', '<div style="max-width:680px;font-family:' . self::FONT . ';font-size:15px;line-height:1.6;color:' . self::INK . '">'
            . self::body($markdown, self::ACCENT, $base) . '</div>');
    }

    /** The Markdown, to HTML with inline styles. */
    public static function body(string $markdown, string $accent = self::ACCENT, string $base = ''): string
    {
        $html = Markdown::document($markdown);
        $f = self::FONT;
        $styles = [
            'p' => "margin:0 0 14px;font-family:$f;font-size:15px;line-height:1.6;color:" . self::INK,
            'h1' => "margin:24px 0 10px;font-family:$f;font-size:20px;line-height:1.3;font-weight:700;color:" . self::INK,
            'h2' => "margin:26px 0 10px;padding-bottom:6px;border-bottom:1px solid " . self::LINE . ";font-family:$f;font-size:18px;line-height:1.3;font-weight:700;color:" . self::INK,
            'h3' => "margin:22px 0 8px;font-family:$f;font-size:16px;line-height:1.35;font-weight:700;color:" . self::INK,
            'h4' => "margin:18px 0 6px;font-family:$f;font-size:15px;font-weight:700;color:" . self::INK,
            'h5' => "margin:16px 0 6px;font-family:$f;font-size:14px;font-weight:700;color:" . self::MUTED,
            'h6' => "margin:16px 0 6px;font-family:$f;font-size:13px;font-weight:700;color:" . self::MUTED,
            'ul' => "margin:0 0 14px;padding-left:22px;font-family:$f;font-size:15px;line-height:1.6;color:" . self::INK,
            'ol' => "margin:0 0 14px;padding-left:24px;font-family:$f;font-size:15px;line-height:1.6;color:" . self::INK,
            'li' => 'margin:0 0 6px',
            'a' => "color:$accent;text-decoration:underline",
            'strong' => 'font-weight:700;color:' . self::INK,
            'blockquote' => "margin:0 0 14px;padding:12px 16px 1px;border-left:4px solid $accent;background:#f5f8f7;color:#3c4a46",
            'code' => "font-family:SFMono-Regular,Consolas,Menlo,monospace;font-size:13px;background:#eef2f1;border-radius:4px;padding:1px 5px",
            'pre' => "margin:0 0 14px;padding:12px 14px;background:#f5f7f6;border:1px solid " . self::LINE . ";border-radius:8px;overflow:auto;white-space:pre-wrap;font-size:13px;line-height:1.5",
            'table' => "width:100%;border-collapse:collapse;margin:0 0 16px;font-family:$f;font-size:14px;line-height:1.45",
            'th' => "padding:8px 10px;border-bottom:2px solid " . self::LINE . ";text-align:left;font-weight:700;background:#f5f8f7;color:" . self::INK,
            'td' => "padding:8px 10px;border-bottom:1px solid " . self::LINE . ";vertical-align:top;color:" . self::INK,
            'hr' => "border:0;border-top:1px solid " . self::LINE . ";margin:22px 0",
            'img' => 'max-width:100%;height:auto;border:0',
        ];
        $html = preg_replace('#<div class="scroll">(.*?)</div>#s', '$1', $html);
        $html = preg_replace_callback('#<li class="task"><input type="checkbox" disabled( checked)?>#', fn($m) => '<li style="list-style:none;margin-left:-20px">' . (($m[1] ?? '') !== '' ? '☑ ' : '☐ '), $html);
        $html = preg_replace('#<pre><code[^>]*>#', '<pre><code style="background:none;padding:0;font-size:13px;font-family:SFMono-Regular,Consolas,Menlo,monospace">', $html);
        $html = preg_replace_callback('#<(\w+)((?:\s[^>]*)?)(/?)>#', function ($m) use ($styles, $base) {
            [$all, $tag, $attrs, $close] = $m;
            $t = strtolower($tag);
            if ($t === 'a') {
                $href = preg_match('/href="([^"]*)"/', $attrs, $h) ? self::link(html_entity_decode($h[1], ENT_QUOTES), $base) : '';
                if ($href === '') return '<a style="color:inherit;text-decoration:none">'; // a page of the app with no address to reach it from a mail app
                $attrs = str_replace($h[0], 'href="' . htmlspecialchars($href, ENT_QUOTES) . '"', $attrs);
            }
            if (!isset($styles[$t]) || str_contains($attrs, 'style="background:none')) return "<$tag$attrs$close>";
            if (preg_match('/style="([^"]*)"/', $attrs, $s)) return "<$tag" . str_replace($s[0], 'style="' . $styles[$t] . ';' . $s[1] . '"', $attrs) . "$close>";
            return "<$tag$attrs style=\"{$styles[$t]}\"$close>";
        }, $html);
        return $html;
    }

    /** A link that works from a mail app: #chat/x and /fred/… are made absolute with the app's address, or dropped. */
    public static function link(string $href, string $base): string
    {
        // a model sometimes writes an address it made up (assistant.example.com…): keep only the path, on this app
        if (preg_match('#^https?://([^/]*\.)?example(\.(com|org|net))?(:\d+)?(/.*)?$#i', $href, $m)) $href = ($m[5] ?? '') !== '' ? $m[5] : '/';
        if ($href === '' || preg_match('#^(https?:|mailto:|tel:)#i', $href)) return $href;
        if (preg_match('#^(?:/[\w-]+)?/files/(\d+)(?:\#p(\d+))?#', $href, $m)) $href = "#file/{$m[1]}" . (isset($m[2]) ? "/p{$m[2]}" : ''); // a document of the library: opened in the app
        if ($base === '' || !preg_match('#^https?://#i', $base)) return '';
        $base = rtrim($base, '/');
        if (str_starts_with($href, '#')) return "$base/$href";
        if (str_starts_with($href, '/')) { $p = parse_url($base); return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $href; }
        return "$base/" . preg_replace('#^\./#', '', $href);
    }

    /** The message often starts with its own title: shown once, in the card's heading. */
    private static function dropTitle(string $md, string $title): string
    {
        if ($title === '') return $md;
        $norm = fn(string $s) => mb_strtolower(trim(preg_replace('/[*_`#:]+/', '', $s)));
        $lines = explode("\n", ltrim(str_replace("\r", '', $md)));
        if ($lines && preg_match('/^#{1,3}\s+(.*)$/', $lines[0], $m) && $norm($m[1]) === $norm(preg_replace('/^.* · /', '', $title))) {
            array_shift($lines);
            return ltrim(implode("\n", $lines));
        }
        return $md;
    }

    /** The first words, shown by mail apps next to the subject. */
    private static function preheader(string $md): string
    {
        $html = preg_replace(['#<table.*?</table>#s', '#<pre.*?</pre>#s', '#<(h\d|li|p|br|blockquote)\b#'], ['', '', ' <$1'], Markdown::document($md));
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return mb_strlen($t) > 140 ? mb_substr($t, 0, 139) . '…' : $t;
    }

    private static function document(string $title, string $preheader, string $content): string
    {
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light">'
            . '<style>@media (max-width:480px){.card{padding:22px 18px!important}}</style>'
            . ($title !== '' ? '<title>' . $e($title) . '</title>' : '') . '</head>'
            . '<body style="margin:0;padding:0;background:#f2f5f4;-webkit-text-size-adjust:100%">'
            . ($preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . $e($preheader) . '</div>' : '')
            . $content . '</body></html>';
    }
}
