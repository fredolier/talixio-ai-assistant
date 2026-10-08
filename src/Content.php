<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Message content is either a string or a list of parts:
 *   ['type' => 'text', 'text' => ...] | ['type' => 'image', 'mime' => ..., 'data' => base64]
 * Providers convert parts to their own format with these helpers.
 */
final class Content
{
    /** @var callable|null fn(int $fileId): image part — set by App */
    public static $imageLoader = null;

    /** Tool results may carry images as "[[image:ID]]" markers: returns [text without markers, image parts]. */
    public static function toolImages(string $result): array
    {
        $images = [];
        $text = preg_replace_callback('/\[\[image:(\d+)\]\]/', function ($m) use (&$images) {
            if (self::$imageLoader) { try { $images[] = (self::$imageLoader)((int)$m[1]); } catch (\Throwable) {} }
            return "[screenshot #{$m[1]}]";
        }, $result);
        return [$text, $images];
    }

    public static function text(string|array $c): string
    {
        if (is_string($c)) return $c;
        return implode("\n\n", array_map(fn($p) => $p['type'] === 'text' ? $p['text'] : '[image]', $c));
    }

    public static function anthropic(string|array $c): string|array
    {
        if (is_string($c)) return $c;
        return array_map(fn($p) => $p['type'] === 'image'
            ? ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $p['mime'], 'data' => $p['data']]]
            : ['type' => 'text', 'text' => $p['text']], $c);
    }

    public static function openai(string|array $c): string|array
    {
        if (is_string($c)) return $c;
        return array_map(fn($p) => $p['type'] === 'image'
            ? ['type' => 'image_url', 'image_url' => ['url' => "data:{$p['mime']};base64,{$p['data']}"]]
            : ['type' => 'text', 'text' => $p['text']], $c);
    }

    /** Apply a converter to every message's content. */
    public static function messages(array $messages, callable $convert): array
    {
        return array_map(fn($m) => ['role' => $m['role'], 'content' => $convert($m['content'])], $messages);
    }
}
