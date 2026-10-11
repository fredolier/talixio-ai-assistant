<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The notes about Talixio itself, in the library under /FAQ (read-only): the pages of docs/faq (using it,
 * privacy, installing and updating, administration), one page per version from CHANGELOG.md (/FAQ/Nouveautés)
 * and the reference documents. Brought up to date when a new version starts, indexed like any document,
 * so the assistant answers questions about itself from them (kb_search in /FAQ).
 */
final class Faq
{
    /** Raised when the way pages are kept changes, so every server runs the sync once more. */
    private const FORMAT = '2';

    public function __construct(private App $app) {}

    /** [library folder, file name, content] of every page, from the files shipped with this version. */
    public static function pages(): array
    {
        $root = dirname(__DIR__);
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/docs/faq", \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'md') continue;
            $rel = substr($f->getPathname(), strlen("$root/docs/faq"));
            $out[] = [Library::normalize(Library::FAQ . dirname($rel)), basename($rel), (string)file_get_contents($f->getPathname())];
        }
        foreach (self::releases((string)@file_get_contents("$root/CHANGELOG.md")) as $v => $text) $out[] = [Library::FAQ . '/Nouveautés', "Talixio $v.md", $text];
        foreach (['README.md' => 'README - anglais.md', 'deploy/docker/README.md' => 'Docker - anglais.md'] as $src => $name) {
            if (is_file("$root/$src")) $out[] = [Library::FAQ . '/Référence', $name, (string)file_get_contents("$root/$src")];
        }
        return $out;
    }

    /** The release notes, one text per version: ['0.14.1' => "# Talixio 0.14.1 (2026-10-10)\n- ..."]. */
    public static function releases(string $changelog): array
    {
        $out = [];
        foreach (preg_split('/^(?=## \d)/m', $changelog) as $part) {
            if (!preg_match('/^## (\d+\.\d+\.\d+)(.*)$/m', $part, $m)) continue;
            $out[$m[1]] = "# Talixio {$m[1]}{$m[2]}\n" . trim(substr($part, strlen($m[0]))) . "\n";
        }
        return $out;
    }

    /**
     * Bring /FAQ in line with this version (once per change): new pages added, changed ones replaced, old ones
     * removed. Pages are found again by folder and name, and anything else in /FAQ is removed, so a sync that was
     * cut short or ran twice at once leaves no copies behind.
     */
    public function sync(): int
    {
        $pages = self::pages();
        $rev = sha1(self::FORMAT . json_encode($pages));
        $st = $this->app->store;
        if ($st->get('faq_rev', '') === $rev) return 0;
        $lock = @fopen($this->app->cfg->dataDir . '/faq.lock', 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) return 0; // another process is on it
        $known = json_decode($st->get('faq_files', ''), true) ?: []; // "folder/name" => [id, sha1]
        $files = $this->app->files(); $lib = $this->app->library();
        $keep = []; $n = 0;
        Library::$system = true;
        try {
            foreach ($pages as [$folder, $name, $text]) {
                $key = "$folder/$name"; $sha = sha1($text);
                [$id, $was] = $known[$key] ?? [0, ''];
                $same = array_map('intval', $st->q('SELECT id FROM files WHERE folder = ? AND name = ? ORDER BY id', [$folder, Files::cleanName($name)])->fetchAll(\PDO::FETCH_COLUMN));
                if (!in_array((int)$id, $same, true)) [$id, $was] = [$same ? max($same) : 0, ''];
                foreach ($same as $other) if ($other !== (int)$id) { $lib->purgeFile($other); $n++; } // copies left by an older version
                $f = $id ? $files->get((int)$id) : null;
                if ($f && $was === $sha) { $keep[$key] = [$id, $sha]; continue; }
                if ($f) {
                    file_put_contents($f['path'], $text);
                    $st->q("UPDATE files SET size = ?, text = NULL, status = 'pending', folder = ? WHERE id = ?", [strlen($text), $folder, $id]);
                } else {
                    $tmp = tempnam(sys_get_temp_dir(), 'faq');
                    file_put_contents($tmp, $text);
                    $id = $files->save($tmp, $name, 'text/markdown', $folder);
                }
                try { $files->text((int)$id); } catch (\Throwable $e) { Log::warn("faq $key: {$e->getMessage()}"); } // Markdown: read and indexed right away
                $keep[$key] = [(int)$id, $sha]; $n++;
            }
            $kept = array_flip(array_column($keep, 0));
            [$w, $a] = Library::within(Library::FAQ);
            foreach ($st->q("SELECT id FROM files WHERE $w", $a)->fetchAll(\PDO::FETCH_COLUMN) as $id) { // pages of older versions, and copies
                if (!isset($kept[(int)$id])) { $lib->purgeFile((int)$id); $n++; }
            }
            [$w, $a] = Library::within(Library::FAQ, 'path'); // folders left empty by an older version
            foreach ($st->q("SELECT path FROM folders WHERE $w", $a)->fetchAll() as $r) {
                [$fw, $fa] = Library::within($r['path']);
                if (!$st->q("SELECT 1 FROM files WHERE $fw LIMIT 1", $fa)->fetchColumn()) $st->q('DELETE FROM folders WHERE path = ?', [$r['path']]);
            }
        } finally {
            Library::$system = false;
        }
        $st->set('faq_files', json_encode($keep));
        $st->set('faq_rev', $rev);
        if ($n) Log::info("faq: $n page(s) brought up to date");
        return $n;
    }
}
