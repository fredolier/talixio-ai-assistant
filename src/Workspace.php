<?php
declare(strict_types=1);
namespace Talixio;

/** Plain Markdown files the assistant (and you) can read and edit. */
final class Workspace
{
    public const FILES = [
        'SOUL.md' => "# Persona\n\nYou are a warm, concise and practical personal assistant.\nAnswer in the user's language. Prefer short answers; offer detail when asked.\n",
        'USER.md' => "# About the user\n\n(The assistant fills this in as it learns: name, family, work, preferences, places.)\n",
        'MEMORY.md' => "# Notes\n\n(Durable notes, decisions and things to remember.)\n",
        'HEARTBEAT.md' => "# Heartbeat checklist\n\n<!-- The assistant reads this every TALIXIO_HEARTBEAT_MINUTES and only messages you\n     if something needs attention. Leave only comments here to disable it. Example:\n- If it's a weekday between 08:00 and 08:30 and I haven't had a briefing today, send one.\n-->\n",
    ];

    public function __construct(private string $dir)
    {
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        foreach (self::FILES as $name => $default) {
            if (!is_file("$dir/$name")) file_put_contents("$dir/$name", $default);
        }
    }

    public function check(string $file): string
    {
        if (!array_key_exists($file, self::FILES)) {
            throw new \InvalidArgumentException('file must be one of ' . implode(', ', array_keys(self::FILES)));
        }
        return "{$this->dir}/$file";
    }

    public function read(string $file): string { return (string)@file_get_contents($this->check($file)); }
    public function write(string $file, string $content): void { file_put_contents($this->check($file), $content, LOCK_EX); }

    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::FILES) as $f) $out[$f] = $this->read($f);
        return $out;
    }

    /** True if the file has content beyond headings and HTML comments. */
    public function hasContent(string $file): bool
    {
        $text = preg_replace(['/<!--.*?-->/s', '/^#.*$/m'], '', $this->read($file));
        return trim((string)$text) !== '';
    }
}
