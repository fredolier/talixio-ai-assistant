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

    /**
     * The per-turn context (time, budget, recalled memories) put in front of the last user message rather than in the
     * system prompt: everything before it then stays the same from one turn to the next, so the provider's prompt
     * cache can reuse the instructions and the earlier conversation. Returns null when the last message is not the user's.
     */
    public static function withContext(array $messages, string $context): ?array
    {
        if ($context === '') return $messages;
        $i = array_key_last($messages);
        if ($i === null || ($messages[$i]['role'] ?? '') !== 'user') return null;
        $head = "[Turn context from Talixio, not written by the user]\n" . trim($context) . "\n[End of turn context]\n\n";
        $c = $messages[$i]['content'];
        $messages[$i]['content'] = is_string($c) ? $head . $c : array_merge([['type' => 'text', 'text' => trim($head)]], $c);
        return $messages;
    }

    /** Apply a converter to every message's content. */
    public static function messages(array $messages, callable $convert): array
    {
        return array_map(fn($m) => ['role' => $m['role'], 'content' => $convert($m['content'])], $messages);
    }
}
