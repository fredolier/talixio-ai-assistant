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
 *
 * A role is the same thing, enriched: a worker definition (a bundle of skills + a model tier + tools + a brief),
 * marked `kind: role` in the header. No parallel store — the registry is these files. See docs/design/agent-factory.md.
 */
final class Skills
{
    public function __construct(private App $app) {}

    /** Header keys written in this order; list-valued ones (skills, tools) are stored comma-joined on one line. */
    private const ORDER = ['name', 'description', 'kind', 'tier', 'skills', 'tools', 'when_to_use', 'when_not', 'scope', 'specialization', 'version', 'uses', 'archived'];

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

    /**
     * Every skill/role. Archived roles are dropped by default, so they fall out of the system-prompt index,
     * role_find, the overlap pass and the catalogue map at once — their file and Assessor history stay on disk;
     * pass $withArchived to list them (for the "Archivés" view and reactivation).
     * @return array<array{name:string,description:string,kind:string,archived:bool,updated:int,used:int}>
     */
    public function all(bool $withArchived = false): array
    {
        $out = [];
        foreach (glob($this->dir() . '/*.md') ?: [] as $f) {
            [$meta] = self::parse((string)file_get_contents($f));
            $name = basename($f, '.md');
            $archived = self::isArchived($meta);
            if ($archived && !$withArchived) continue;
            $out[] = ['name' => $name, 'description' => $meta['description'] ?? '', 'kind' => $meta['kind'] ?? 'skill',
                'archived' => $archived, 'updated' => filemtime($f), 'used' => (int)$this->app->store->get("skill_used_$name", '0')];
        }
        usort($out, fn($a, $b) => $b['used'] <=> $a['used'] ?: strcmp($a['name'], $b['name']));
        return $out;
    }

    /** True when a header carries the archive flag (set by Pilotage's Archiver). */
    public static function isArchived(array $meta): bool
    {
        return ($meta['archived'] ?? '') === 'true';
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

    /** The full header of one skill/role (name, description, and any role fields), or [] if there is no such file. */
    public function meta(string $name): array
    {
        $f = $this->dir() . '/' . self::slug($name) . '.md';
        if (!is_file($f)) return [];
        [$meta] = self::parse((string)file_get_contents($f));
        return $meta;
    }

    public function read(string $name, bool $countUse = true): string
    {
        $f = $this->dir() . '/' . self::slug($name) . '.md';
        if (!is_file($f)) return "error: no skill named '$name'. Existing: " . implode(', ', array_column($this->all(), 'name'));
        if ($countUse) $this->app->store->set('skill_used_' . self::slug($name), (string)((int)$this->app->store->get('skill_used_' . self::slug($name), '0') + 1));
        return (string)file_get_contents($f);
    }

    /**
     * Save or replace a skill. $extra carries role fields (kind, tier, skills, tools, …), preserved in the header.
     * The body keeps whatever the model wrote (any header it added is dropped so it can't fight $extra).
     */
    public function save(string $name, string $description, string $body, array $extra = []): string
    {
        $slug = self::slug($name);
        $f = $this->dir() . "/$slug.md";
        $existed = is_file($f);
        $description = trim(preg_replace('/\s+/', ' ', $description));
        if ($description === '') throw new \InvalidArgumentException('a skill needs a description saying when to use it');
        [, $body] = self::parse($body); // drop any header the model wrote itself
        $header = ['name' => $slug, 'description' => $description] + $extra;
        file_put_contents($f, $this->header($header) . "\n" . trim($body) . "\n", LOCK_EX);
        return ($existed ? 'updated' : 'saved') . " {$this->noun($header)} '$slug'";
    }

    /** A YAML-ish header, known keys first (ORDER), then any others, skipping empty values. */
    private function header(array $meta): string
    {
        $meta = array_filter($meta, fn($v) => trim((string)$v) !== '');
        $keys = array_merge(array_intersect(self::ORDER, array_keys($meta)), array_diff(array_keys($meta), self::ORDER));
        $lines = '';
        foreach ($keys as $k) $lines .= "$k: " . str_replace("\n", ' ', (string)$meta[$k]) . "\n";
        return "---\n$lines---\n";
    }

    private function noun(array $meta): string { return ($meta['kind'] ?? 'skill') === 'role' ? 'role' : 'skill'; }

    /**
     * Search-before-create: rank skills/roles by how well they already cover `query`, so an existing one is reused
     * instead of a near-duplicate being made. Lexical overlap over name, description, skills and tools — no index to
     * warm, always available. `kind` filters to 'role' or 'skill' when given.
     * @return array<array{name:string,description:string,kind:string,score:float}>
     */
    public function find(string $query, int $limit = 5, ?string $kind = null): array
    {
        $want = self::tokens($query);
        if (!$want) return [];
        $out = [];
        foreach ($this->all() as $s) {
            $meta = $this->meta($s['name']);
            if ($kind !== null && ($meta['kind'] ?? 'skill') !== $kind) continue;
            $hay = self::tokens($s['name'] . ' ' . $s['description'] . ' ' . ($meta['skills'] ?? '') . ' ' . ($meta['tools'] ?? '') . ' ' . ($meta['when_to_use'] ?? ''));
            if (!$hay) continue;
            $hit = count(array_intersect($want, $hay));
            if (!$hit) continue;
            $score = $hit / count($want);
            if (array_intersect($want, self::tokens($s['name']))) $score += 0.15; // a name match is a strong signal
            $out[] = ['name' => $s['name'], 'description' => $s['description'], 'kind' => $meta['kind'] ?? 'skill', 'score' => round(min($score, 1.0), 2)];
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }

    /**
     * The duplicate signal, made pairwise: how close every pair of roles reads, over the same fields find() ranks on
     * (name, description, skills, tools, when_to_use). A symmetric set-cosine in [0,1]; pairs at or above $threshold
     * are the "doublons" Pilotage warns about. Computed on demand, never stored as truth — a suggestion, not a verdict.
     * @return array<array{a:string,b:string,score:float}> unique unordered pairs, closest first
     */
    public function overlaps(float $threshold = 0.6): array
    {
        $bags = [];
        foreach ($this->all() as $s) {
            $meta = $this->meta($s['name']);
            if (($meta['kind'] ?? 'skill') !== 'role') continue;
            $toks = self::tokens($s['name'] . ' ' . $s['description'] . ' ' . ($meta['skills'] ?? '') . ' ' . ($meta['tools'] ?? '') . ' ' . ($meta['when_to_use'] ?? ''));
            if ($toks) $bags[$s['name']] = array_flip($toks);
        }
        $names = array_keys($bags);
        $out = [];
        for ($i = 0; $i < count($names); $i++) for ($j = $i + 1; $j < count($names); $j++) {
            $a = $bags[$names[$i]]; $b = $bags[$names[$j]];
            $shared = count(array_intersect_key($a, $b));
            if (!$shared) continue;
            $score = round($shared / sqrt(count($a) * count($b)), 2);
            if ($score >= $threshold) $out[] = ['a' => $names[$i], 'b' => $names[$j], 'score' => $score];
        }
        usort($out, fn($x, $y) => $y['score'] <=> $x['score']);
        return $out;
    }

    /** Set (or clear, when empty) one header field of a role in place, keeping its brief and the rest of the header. */
    public function setField(string $name, string $key, string $value): void
    {
        $raw = $this->raw($name);
        if ($raw === null) throw new \InvalidArgumentException("no skill named '$name'");
        [$meta, $body] = self::parse($raw);
        $value = trim(preg_replace('/\s+/', ' ', $value));
        if ($value === '') unset($meta[$key]); else $meta[$key] = $value;
        $extra = $meta; unset($extra['name'], $extra['description']);
        $this->save($meta['name'] ?? $name, $meta['description'] ?? 'role', $body, $extra);
    }

    /** @return string[] distinct lowercase word stems of length ≥ 3 (accents folded), for lexical matching. */
    private static function tokens(string $s): array
    {
        $s = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
        preg_match_all('/[a-z0-9]{3,}/', $s, $m);
        return array_values(array_unique($m[0]));
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
        $extra = $meta; unset($extra['name'], $extra['description']);
        $this->save($meta['name'] ?? $name, $meta['description'] ?? 'Procedure', $body, $extra);
        if (isset($meta['name']) && self::slug($meta['name']) !== self::slug($name)) $this->delete($name); // renamed
    }

    public function delete(string $name): bool
    {
        $f = $this->dir() . '/' . self::slug($name) . '.md';
        return is_file($f) && unlink($f);
    }
}
