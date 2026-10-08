<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Procedures the assistant has learned, as Markdown files in workspace/skills/ with a short header:
 *   ---
 *   name: weekly-report
 *   description: When Fred asks for his weekly report: sources, format, who gets it
 *   ---
 * Only names and descriptions go into every prompt; the full text is read when a task matches.
 */
final class Skills
{
    public function __construct(private App $app) {}

    private function dir(): string
    {
        $d = $this->app->cfg->workspaceDir() . '/skills';
        if (!is_dir($d)) mkdir($d, 0750, true);
        return $d;
    }

    public static function slug(string $name): string
    {
        $s = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)), '-');
        if ($s === '') throw new \InvalidArgumentException('skill name needs letters or digits');
        return substr($s, 0, 60);
    }

    /** @return array<array{name:string,description:string,updated:int,used:int}> */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir() . '/*.md') ?: [] as $f) {
            [$meta] = self::parse((string)file_get_contents($f));
            $name = basename($f, '.md');
            $out[] = ['name' => $name, 'description' => $meta['description'] ?? '', 'updated' => filemtime($f), 'used' => (int)$this->app->store->get("skill_used_$name", '0')];
        }
        usort($out, fn($a, $b) => $b['used'] <=> $a['used'] ?: strcmp($a['name'], $b['name']));
        return $out;
    }

    /** One line per skill for the system prompt. */
    public function index(int $max = 60): string
    {
        return implode("\n", array_map(fn($s) => "- {$s['name']}: " . mb_substr($s['description'], 0, 160), array_slice($this->all(), 0, $max)));
    }

    public static function parse(string $text): array
    {
        $meta = [];
        if (preg_match('/^---\n(.*?)\n---\n?/s', str_replace("\r\n", "\n", $text), $m)) {
            foreach (explode("\n", $m[1]) as $l) if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $meta[trim($k)] = trim($v); }
            $text = substr(str_replace("\r\n", "\n", $text), strlen($m[0]));
        }
        return [$meta, trim($text)];
    }

    public function read(string $name, bool $countUse = true): string
    {
        $f = $this->dir() . '/' . self::slug($name) . '.md';
        if (!is_file($f)) return "error: no skill named '$name'. Existing: " . implode(', ', array_column($this->all(), 'name'));
        if ($countUse) $this->app->store->set('skill_used_' . self::slug($name), (string)((int)$this->app->store->get('skill_used_' . self::slug($name), '0') + 1));
        return (string)file_get_contents($f);
    }

    public function save(string $name, string $description, string $body): string
    {
        $slug = self::slug($name);
        $f = $this->dir() . "/$slug.md";
        $existed = is_file($f);
        $description = trim(preg_replace('/\s+/', ' ', $description));
        if ($description === '') throw new \InvalidArgumentException('a skill needs a description saying when to use it');
        [, $body] = self::parse($body); // drop any header the model wrote itself
        file_put_contents($f, "---\nname: $slug\ndescription: $description\n---\n\n" . trim($body) . "\n", LOCK_EX);
        return ($existed ? 'updated' : 'saved') . " skill '$slug'";
    }

    /** Raw file (header included), for the editor. */
    public function raw(string $name): ?string
    {
        $f = $this->dir() . '/' . self::slug($name) . '.md';
        return is_file($f) ? (string)file_get_contents($f) : null;
    }

    public function writeRaw(string $name, string $content): void
    {
        [$meta, $body] = self::parse($content);
        $this->save($meta['name'] ?? $name, $meta['description'] ?? 'Procedure', $body);
        if (isset($meta['name']) && self::slug($meta['name']) !== self::slug($name)) $this->delete($name); // renamed
    }

    public function delete(string $name): bool
    {
        $f = $this->dir() . '/' . self::slug($name) . '.md';
        return is_file($f) && unlink($f);
    }
}
