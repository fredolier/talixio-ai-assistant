<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Filing new documents (/Inbox): which folder, and a clear name.
 * 1. The folder is first guessed in embedding space from the documents already filed (Classifier):
 *    free, instant, and it learns from every move you make.
 * 2. When that is not clear enough (or the name is meaningless, like "scan_0012.pdf"), the fast model
 *    reads the start of the document and the folder tree and proposes both.
 * TALIXIO_AUTO_FILE: off | suggest (you accept with one click) | auto (confident ones are filed directly).
 */
final class Filing
{
    private const SKIP = ['/Inbox', '/Screenshots'];

    public function __construct(private App $app) {}

    public static function genericName(string $name): bool
    {
        $base = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name);
        return (bool)preg_match('/^(scan|scanned|img|image|dsc|pxl|photo|document|doc|file|fichier|download|untitled|sans[ _-]?titre|capture|screenshot|whatsapp|signal|telegram|shared|attachment|piece[ _-]?jointe|pj|recording|enregistrement|new|nouveau)?[\s_\-.()]*[\d\s_\-.()]*$/iu', $base)
            || mb_strlen($base) < 3;
    }

    /** Vector of a file (its first indexed passage), computed and stored if the background embedding hasn't run yet. */
    private function fileVector(int $id): ?string
    {
        $st = $this->app->store; $model = $this->app->embeddings->model();
        $doc = $st->q("SELECT id, text FROM docs WHERE kind IN ('file','transcript') AND ref = ? ORDER BY id LIMIT 1", [$id])->fetch();
        if (!$doc) return null;
        $vec = $st->q('SELECT vec FROM embeddings WHERE doc_id = ? AND model = ?', [$doc['id'], $model])->fetchColumn();
        if ($vec === false) {
            $vec = $this->app->embeddings->embed([$doc['text']], 'document')[0];
            $st->q('INSERT OR REPLACE INTO embeddings(doc_id, model, vec) VALUES(?,?,?)', [$doc['id'], $model, $vec]);
        }
        return $vec;
    }

    /** [folder, vec] of filed documents: the library is the training set. */
    private function examples(int $except): array
    {
        $in = implode(',', array_fill(0, count(self::SKIP), '?'));
        return $this->app->store->q("SELECT f.folder, e.vec FROM files f
            JOIN docs d ON d.id = (SELECT MIN(id) FROM docs WHERE kind IN ('file','transcript') AND ref = f.id)
            JOIN embeddings e ON e.doc_id = d.id AND e.model = ?
            WHERE f.id != ? AND f.folder NOT IN ($in) AND f.folder != '/FAQ' AND f.folder NOT LIKE '/FAQ/%' ORDER BY f.id DESC LIMIT 5000", [$this->app->embeddings->model(), $except, ...self::SKIP])->fetchAll(\PDO::FETCH_NUM);
    }

    /**
     * Work out where a file should go; stores the suggestion on the file (and files it in "auto" mode when sure).
     * @return array{folder:string,name:string,why:string,confidence:string,by:string}|null
     */
    public function suggest(int $id, bool $useModel = true): ?array
    {
        $f = $this->app->files()->get($id);
        if (!$f || $f['status'] !== 'ready') return null;
        if (trim((string)$f['text']) === '' && !self::genericName($f['name']) && $f['folder'] === Library::INBOX) { // nothing to read: leave it
            $this->app->store->q("UPDATE files SET suggestion = '{\"done\":true}' WHERE id = ?", [$id]);
            return null;
        }
        $s = ['folder' => '', 'name' => $f['name'], 'why' => '', 'confidence' => 'low', 'by' => ''];
        $guess = null;
        if ($this->app->embeddings->enabled()) {
            try {
                $vec = $this->fileVector($id);
                if ($vec !== null) {
                    $guess = Classifier::rank($vec, $this->examples($id), $this->app->cfg->recallMinSim * 0.9, 0.04, 2);
                    if ($guess['confident']) $s = ['folder' => $guess['label'], 'why' => 'Similar to the documents already in ' . $guess['label'], 'confidence' => 'high', 'by' => 'similar'] + $s;
                }
            } catch (\Throwable $e) { Log::warn("filing #$id: {$e->getMessage()}"); }
        }
        $needName = self::genericName($f['name']);
        if (($s['folder'] === '' || $needName) && $useModel && $this->app->budget()->status()['state'] === 'ok') {
            $m = $this->askModel($f, $s['folder'], $guess['ranking'] ?? []);
            if ($m) {
                if ($s['folder'] === '' && $m['folder'] !== '') $s = ['folder' => $m['folder'], 'why' => $m['why'], 'confidence' => $m['confidence'], 'by' => 'model'] + $s;
                if ($needName && $m['name'] !== '') $s['name'] = $m['name'];
            }
        }
        if ($s['folder'] === '' && $s['name'] === $f['name']) { $this->app->store->q("UPDATE files SET suggestion = '{\"done\":true}' WHERE id = ?", [$id]); return null; }
        if ($s['folder'] === '') $s['folder'] = $f['folder'];
        $this->app->store->q('UPDATE files SET suggestion = ? WHERE id = ?', [json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
        if ($this->app->cfg->autoFile === 'auto' && $s['confidence'] === 'high' && $f['folder'] === Library::INBOX) {
            $this->apply($id);
            Log::info("filing: #$id → " . Library::join($s['folder'], $s['name']));
        }
        return $s;
    }

    /** The fast model reads the beginning of the document and the folder tree. */
    private function askModel(array $f, string $knownFolder, array $ranking): ?array
    {
        $tree = array_values(array_filter(array_column($this->app->library()->tree(), 'path'), fn($p) => !in_array($p, self::SKIP, true) && !Library::readonly($p)));
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $ask = "A document arrived in the user's library inbox. "
            . ($knownFolder !== '' ? "Its folder is already decided: $knownFolder. Only propose a clear file name.\n" : "Choose the best folder among the existing ones; propose a new subfolder of an existing folder only if nothing fits.\n")
            . "File name: {$f['name']}\nType: {$f['kind']}, received " . date('Y-m-d', (int)$f['created']) . "\n"
            . ($ranking ? 'Folders whose documents look most similar: ' . implode(', ', array_keys($ranking)) . "\n" : '')
            . "Existing folders:\n" . (implode("\n", array_slice($tree, 0, 300)) ?: '(none yet)') . "\n\n"
            . "Beginning of the document:\n" . mb_substr((string)$f['text'], 0, 2500) . "\n\n"
            . 'Reply with JSON only: {"folder": "/Path", "name": "clear name' . ($ext ? ".$ext" : '') . '", "confidence": "high" or "low", "why": "a few words"}. '
            . 'A good name says what it is, from whom and when, in the document\'s language, e.g. "Facture EDF 2026-10.pdf", "Contrat de bail Dupont 2025.pdf".';
        try {
            [$text] = $this->app->agent()->run('filing', 'fast', 'none', [['role' => 'user', 'content' => $ask]], '', 'classify');
        } catch (\Throwable $e) {
            Log::warn("filing model: {$e->getMessage()}");
            return null;
        }
        if (!preg_match('/\{.*\}/s', $text, $m) || !is_array($d = json_decode($m[0], true))) return null;
        $folder = isset($d['folder']) && trim((string)$d['folder']) !== '' ? Library::normalize((string)$d['folder']) : '';
        if ($folder === '/' || in_array($folder, self::SKIP, true) || Library::readonly($folder)) $folder = '';
        $name = trim(basename(str_replace('\\', '/', (string)($d['name'] ?? ''))));
        if ($name !== '' && $ext && !str_ends_with(strtolower($name), ".$ext")) $name .= ".$ext";
        return ['folder' => $knownFolder !== '' ? '' : $folder, 'name' => mb_substr($name, 0, 120), 'confidence' => ($d['confidence'] ?? '') === 'high' && $this->app->library()->exists($folder) ? 'high' : 'low',
            'why' => mb_substr(trim((string)($d['why'] ?? '')), 0, 200)];
    }

    /** File it as suggested (or with the user's changes). */
    public function apply(int $id, ?string $folder = null, ?string $name = null): array
    {
        $f = $this->app->files()->get($id) ?? throw new \InvalidArgumentException("no file $id");
        $s = json_decode((string)$f['suggestion'], true) ?: [];
        $folder ??= $s['folder'] ?? ''; $name ??= $s['name'] ?? '';
        $lib = $this->app->library();
        if ($folder !== '' && $folder !== $f['folder']) $f = $lib->moveFile($id, $folder);
        if ($name !== '' && $name !== $f['name']) $f = $lib->renameFile($id, $name);
        $this->app->store->q("UPDATE files SET suggestion = '{\"done\":true}' WHERE id = ?", [$id]);
        return $this->app->files()->get($id);
    }

    public function dismiss(int $id): void { $this->app->store->q("UPDATE files SET suggestion = '{\"dismissed\":true}' WHERE id = ?", [$id]); }

    /** Inbox files ready and not yet looked at (background indexer). */
    public function pending(): array
    {
        return array_map('intval', array_column($this->app->store->q("SELECT id FROM files WHERE folder = ? AND status = 'ready' AND suggestion IS NULL ORDER BY id LIMIT 10", [Library::INBOX])->fetchAll(), 'id'));
    }
}
