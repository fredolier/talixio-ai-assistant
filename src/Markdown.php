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
}
