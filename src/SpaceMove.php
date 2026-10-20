<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Moving a space to another assistant of the same person, whole: its conversation and summaries, settings,
 * routines, jobs, tasks (with their history, time and links), what the Mind learned in it, and its library
 * folder with the documents its messages, jobs and tasks point to. export() packs it as a .zip, import() unpacks
 * it in the other assistant with new ids, remove() clears it here once the other one has it. The browser carries
 * the package (Links: an import ticket), or the person downloads it and imports the file. AI account keys never
 * travel: the moved space uses the other assistant's main settings.
 */
final class SpaceMove
{
    public const FORMAT = 'talixio-space';
    public const VERSION = 1;

    public function __construct(private App $app) {}

    /** A .zip of the space (a temp file the caller removes). */
    public function export(int $id): string
    {
        $sp = $id ? $this->app->spaces()->get($id) : null;
        if (!$sp) throw new \InvalidArgumentException('no such space');
        $st = $this->app->store;
        $this->app->board(); // its tables exist
        if ($st->q("SELECT 1 FROM jobs WHERE space = ? AND status IN ('queued','running')", [$id])->fetchColumn())
            throw new \InvalidArgumentException('A job of this space is still running. Move it once the job has finished.');
        $rows = fn(string $sql, array $a = []) => $st->q($sql, $a)->fetchAll();
        $d = [];
        $d['messages'] = $rows('SELECT * FROM messages WHERE space = ? ORDER BY id', [$id]);
        $d['summaries'] = $rows('SELECT * FROM summaries WHERE space = ? ORDER BY id', [$id]);
        $d['tasks'] = $rows('SELECT * FROM tasks WHERE space = ? ORDER BY id', [$id]);
        $d['jobs'] = $rows('SELECT * FROM jobs WHERE space = ? ORDER BY id', [$id]);
        $jobs = array_map('intval', array_column($d['jobs'], 'id'));
        $d['tool_log'] = $jobs ? $rows('SELECT * FROM tool_log WHERE job_id IN (' . implode(',', $jobs) . ') ORDER BY id') : [];
        $d['cards'] = $rows('SELECT * FROM cards WHERE space = ? ORDER BY id', [$id]);
        $cards = array_map('intval', array_column($d['cards'], 'id'));
        $in = $cards ? implode(',', $cards) : '0';
        $d['card_deps'] = $rows("SELECT * FROM card_deps WHERE card IN ($in) AND depends_on IN ($in)");
        $d['card_log'] = $rows("SELECT * FROM card_log WHERE card IN ($in) ORDER BY id");
        $d['card_time'] = $rows("SELECT * FROM card_time WHERE card IN ($in) ORDER BY id");
        $d['mind_beliefs'] = $rows('SELECT * FROM mind_beliefs WHERE space = ? ORDER BY id', [$id]);
        $d['mind_journal'] = $rows('SELECT * FROM mind_journal WHERE space = ? ORDER BY id', [$id]);
        // the documents: the space's folder, and those its messages, jobs and tasks point to wherever they are
        $folder = (string)$sp['folder'];
        $fileIds = [];
        if ($folder !== '') {
            [$w, $a] = Library::within($folder);
            foreach ($st->q("SELECT id FROM files WHERE $w AND deleted_at IS NULL", $a)->fetchAll(\PDO::FETCH_COLUMN) as $f) $fileIds[(int)$f] = true;
        }
        foreach ($d['messages'] as $m) {
            foreach (json_decode((string)$m['files'], true) ?: [] as $f) $fileIds[(int)$f] = true;
            foreach (self::fileRefs((string)$m['content']) as $f) $fileIds[$f] = true;
        }
        foreach ($d['jobs'] as $j) foreach ([$j['file_id'], $j['report_file']] as $f) if ($f) $fileIds[(int)$f] = true;
        foreach ($d['cards'] as $c) {
            foreach (json_decode((string)$c['links'], true) ?: [] as $l) if (($l['type'] ?? '') === 'file' && (int)($l['ref'] ?? 0)) $fileIds[(int)$l['ref']] = true;
            foreach (self::fileRefs((string)$c['body']) as $f) $fileIds[$f] = true;
        }
        $d['files'] = [];
        foreach (array_keys($fileIds) as $f) {
            $r = $st->q('SELECT * FROM files WHERE id = ? AND deleted_at IS NULL', [$f])->fetch();
            if ($r && is_file((string)$r['path']) && !Library::readonly((string)$r['folder'])) $d['files'][] = $r;
        }
        $d['folders'] = [];
        if ($folder !== '') {
            [$w, $a] = Library::within($folder, 'path');
            $d['folders'] = $st->q("SELECT path FROM folders WHERE $w ORDER BY path", $a)->fetchAll(\PDO::FETCH_COLUMN);
        }
        $space = $sp;
        unset($space['id'], $space['account'], $space['last_read']);
        $kv = [];
        foreach (['summarized' => Store::summarizedKey($id), 'portrait' => "mind_portrait_$id", 'reflected' => "mind_reflected_$id", 'log' => "space_log_$id"] as $k => $key) $kv[$k] = $st->get($key, '');
        $kv['card_seq'] = $sp['code'] !== '' ? $st->get("card_seq_{$sp['code']}", '0') : '0';
        $meta = ['format' => self::FORMAT, 'version' => self::VERSION, 'talixio' => trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION')), 'from' => $this->app->links()->current(),
            'exported' => time(), 'space' => $space, 'kv' => $kv,
            'counts' => ['messages' => count($d['messages']), 'tasks' => count($d['cards']), 'routines' => count($d['tasks']), 'jobs' => count($d['jobs']), 'files' => count($d['files'])]];
        $zipPath = tempnam(sys_get_temp_dir(), 'tlxsp');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('could not write the package');
        $zip->addFromString('space.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $zip->addFromString('data.json', json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        foreach ($d['files'] as $f) {
            $zip->addFile((string)$f['path'], 'files/' . (int)$f['id']);
            if (preg_match('/^(audio|video|image)\//', (string)$f['mime']) || preg_match('/\.(zip|gz|jpe?g|png|mp[34]|m4a|ogg|webm|docx|xlsx|pptx)$/i', (string)$f['name']))
                $zip->setCompressionName('files/' . (int)$f['id'], \ZipArchive::CM_STORE); // already compressed
        }
        if (!$zip->close()) throw new \RuntimeException('could not write the package');
        Log::info("space #$id ({$sp['name']}) exported: " . json_encode($meta['counts']));
        return $zipPath;
    }

    /** What a package holds, without importing it: the space.json part. */
    public static function peek(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) throw new \InvalidArgumentException('This is not a space package (.zip).');
        $meta = json_decode((string)$zip->getFromName('space.json'), true);
        $zip->close();
        if (!is_array($meta) || ($meta['format'] ?? '') !== self::FORMAT || !is_array($meta['space'] ?? null)) throw new \InvalidArgumentException('This is not a space package.');
        if ((int)($meta['version'] ?? 0) > self::VERSION) throw new \InvalidArgumentException('This package comes from a newer Talixio: update this assistant first.');
        return $meta;
    }

    /** Unpack a package as a new space here. Returns the space. */
    public function import(string $zipPath, string $by = 'app'): array
    {
        $meta = self::peek($zipPath);
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::RDONLY);
        $d = json_decode((string)$zip->getFromName('data.json'), true);
        if (!is_array($d)) { $zip->close(); throw new \InvalidArgumentException('This space package is damaged.'); }
        $st = $this->app->store;
        $board = $this->app->board();
        $created = [];
        $st->db->beginTransaction();
        try {
            $sp = $meta['space'];
            $from = (string)($meta['from']['name'] ?? '') ?: 'another assistant';
            // ---- the space: a free name, task code and folder
            $name = trim((string)($sp['name'] ?? '')) ?: 'Moved space';
            for ($n = 2, $base = mb_substr($name, 0, 55); $st->q('SELECT 1 FROM spaces WHERE slug = ?', [Spaces::slug($name)])->fetchColumn() || in_array(Spaces::slug($name), ['general', 'all'], true); $n++) $name = "$base $n";
            $oldCode = (string)($sp['code'] ?? '');
            $code = $oldCode;
            try { if ($code !== '') $code = Board::cleanPrefix($code); } catch (\InvalidArgumentException) { $code = ''; }
            $taken = fn(string $c) => $c === 'GEN' || $st->q('SELECT 1 FROM spaces WHERE code = ?', [$c])->fetchColumn()
                || $st->q('SELECT 1 FROM cards WHERE code LIKE ?', [$c . '-%'])->fetchColumn();
            if ($code === '' || $taken($code)) {
                $stem = substr($code !== '' ? $code : (strtoupper(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)) . 'XXX'), 0, 3);
                if (!preg_match('/^[A-Z]/', $stem)) $stem = 'SPX';
                for ($i = 2, $code = $stem; $taken($code); $i++) $code = substr($stem, 0, $i < 10 ? 2 : 1) . $i;
            }
            $oldFolder = (string)($sp['folder'] ?? '');
            $folder = '';
            if ($oldFolder !== '') {
                $folder = Library::normalize($oldFolder);
                if ($folder === '/' || Library::readonly($folder)) $folder = '/' . trim(preg_replace('#[/\\\\]+#', ' ', $name));
                for ($n = 2, $base = $folder; $this->folderUsed($folder); $n++) $folder = "$base ($n)";
            }
            $row = ['name' => $name, 'slug' => Spaces::slug($name), 'folder' => $folder, 'code' => $code, 'account' => '', 'last_read' => 0,
                'created' => (int)($sp['created'] ?? time())];
            foreach (['instructions', 'notes', 'tier', 'color', 'archived', 'privacy'] as $k) if (array_key_exists($k, $sp)) $row[$k] = $sp[$k];
            if (!in_array($row['tier'] ?? '', Tier::NAMES, true)) $row['tier'] = 'standard';
            if (!in_array($row['privacy'] ?? '', Privacy::LEVELS, true)) $row['privacy'] = 'internal';
            if (!preg_match('/^#[0-9a-f]{6}$/i', (string)($row['color'] ?? ''))) $row['color'] = Spaces::COLORS[0];
            $space = $this->insert('spaces', $row);
            if (($row['privacy'] ?? 'internal') !== 'internal') $st->set('privacy_rev', (string)((int)$st->get('privacy_rev', '0') + 1));

            // ---- documents: the folder tree, then each file with its read text (nothing to read again)
            $under = fn(string $f, string $root) => $root !== '' && $root !== '/' && ($f === $root || str_starts_with($f, rtrim($root, '/') . '/'));
            $others = array_filter(array_map(fn($sp) => Library::normalize((string)$sp['folder']), $this->app->spaces()->all(true)), fn($r) => $r !== Library::normalize($folder));
            $mapFolder = function (string $f) use ($oldFolder, $folder, $under, $others): string {
                $f = Library::normalize($f);
                if ($oldFolder !== '' && $under($f, Library::normalize($oldFolder)))
                    $f = $folder . substr($f, strlen(Library::normalize($oldFolder)));
                elseif (array_filter($others, fn($r) => $under($f, $r))) $f = ($folder !== '' ? $folder : '/Inbox') . '/Linked files' . $f; // never into another space's folder
                return Library::readonly($f) ? '/Inbox' : $f;
            };
            $lib = $this->app->library();
            foreach ($d['folders'] ?? [] as $p) $lib->ensureFolder($mapFolder((string)$p));
            if ($folder !== '') $lib->ensureFolder($folder);
            $files = [];
            $dir = $this->app->cfg->filesDir() . '/' . date('Y/m');
            if (!is_dir($dir)) mkdir($dir, 0750, true);
            foreach ($d['files'] ?? [] as $f) {
                $old = (int)$f['id'];
                $in = $zip->getStream("files/$old");
                if (!$in) continue;
                $fold = $mapFolder((string)$f['folder']);
                $lib->ensureFolder($fold);
                $fname = Library::cleanName((string)$f['name']) ?: 'file';
                $fname = $lib->freeName($fold, $fname);
                unset($f['id'], $f['deleted_at'], $f['deleted_with']);
                $f = ['name' => $fname, 'folder' => $fold, 'path' => '', 'suggestion' => null] + $f;
                if (($f['status'] ?? '') === 'processing') $f['status'] = 'pending';
                $nid = $this->insert('files', $f);
                $path = "$dir/$nid" . (preg_match('/\.([A-Za-z0-9]{1,10})$/', $fname, $x) ? '.' . strtolower($x[1]) : '');
                $out = fopen($path, 'wb');
                stream_copy_to_stream($in, $out);
                fclose($out); fclose($in);
                chmod($path, 0640);
                $created[] = $path;
                $st->q('UPDATE files SET path = ?, size = ? WHERE id = ?', [$path, filesize($path), $nid]);
                $files[$old] = $nid;
            }
            $refs = fn(string $t) => self::rewriteFileRefs($t, $files);

            // ---- jobs (and their steps), routines, conversation, summaries
            $jobs = [];
            foreach ($d['jobs'] ?? [] as $j) {
                $old = (int)$j['id'];
                unset($j['id']);
                $j['space'] = $space;
                $j['file_id'] = isset($files[(int)$j['file_id']]) ? $files[(int)$j['file_id']] : null;
                $j['report_file'] = isset($files[(int)$j['report_file']]) ? $files[(int)$j['report_file']] : null;
                if (($j['folder'] ?? '') !== '') $j['folder'] = $mapFolder((string)$j['folder']);
                if (in_array($j['status'] ?? '', ['queued', 'running'], true)) $j['status'] = 'failed';
                $j['result'] = $refs((string)($j['result'] ?? ''));
                $jobs[$old] = $this->insert('jobs', $j);
            }
            foreach ($d['jobs'] ?? [] as $j) if ($j['parent'] && isset($jobs[(int)$j['parent']])) $st->q('UPDATE jobs SET parent = ? WHERE id = ?', [$jobs[(int)$j['parent']], $jobs[(int)$j['id']]]);
            foreach ($d['tool_log'] ?? [] as $t) {
                if (!isset($jobs[(int)$t['job_id']])) continue;
                unset($t['id']); $t['job_id'] = $jobs[(int)$t['job_id']];
                $this->insert('tool_log', $t);
            }
            $tasks = [];
            foreach ($d['tasks'] ?? [] as $t) {
                $old = (int)$t['id'];
                unset($t['id']);
                $t['space'] = $space;
                $t['job'] = isset($jobs[(int)$t['job']]) ? $jobs[(int)$t['job']] : null;
                if ($t['job'] === null && trim((string)$t['prompt']) === '') continue; // its job did not come along
                $tasks[$old] = $this->insert('tasks', $t);
            }
            $msgs = [];
            foreach ($d['messages'] ?? [] as $m) {
                $old = (int)$m['id'];
                unset($m['id']);
                $m['space'] = $space;
                $m['content'] = $refs((string)$m['content']);
                $mf = array_values(array_filter(array_map(fn($x) => $files[(int)$x] ?? null, json_decode((string)$m['files'], true) ?: [])));
                $m['files'] = $mf ? json_encode($mf) : null;
                $mm = json_decode((string)$m['meta'], true);
                if (is_array($mm) && isset($mm['routine'])) { if (isset($tasks[(int)$mm['routine']])) $mm['routine'] = $tasks[(int)$mm['routine']]; else unset($mm['routine'], $mm['votes']); }
                $m['meta'] = is_array($mm) ? json_encode($mm) : null;
                $nid = $this->insert('messages', $m);
                $msgs[$old] = $nid;
                [$label, $text] = match (true) { // indexed as Store::addMessage does
                    $m['channel'] === 'job' && $m['role'] === 'user' => ['job report', preg_replace('/^\[[^\]]*\]\s*/', '', $m['content'])],
                    $m['channel'] === 'schedule' && $m['role'] === 'user' => ['scheduled task', preg_replace('/^\[[^\]]*\]\s*/', '', $m['content'])],
                    default => ["{$m['role']} via {$m['channel']}", $m['content']],
                };
                $st->addDoc('message', $nid, $label, (string)$text, (int)$m['ts'], $space);
            }
            $mapMsg = function (int $old, bool $up) use ($msgs): int { // the nearest moved message (a summary may start on another space's id)
                if (isset($msgs[$old])) return $msgs[$old];
                $best = null;
                foreach ($msgs as $o => $n) if ($up ? ($o > $old && ($best === null || $o < $best)) : ($o < $old && ($best === null || $o > $best))) $best = $o;
                return $best === null ? 0 : $msgs[$best];
            };
            $sums = [];
            foreach ($d['summaries'] ?? [] as $s) {
                $sums[(int)$s['id']] = $st->addSummary($mapMsg((int)$s['from_id'], true), $mapMsg((int)$s['to_id'], false), (int)$s['ts_from'], (int)$s['ts_to'], (string)$s['text'], (int)($s['level'] ?? 1), $space);
            }
            foreach ($d['summaries'] ?? [] as $s) if ($s['merged_into'] && isset($sums[(int)$s['merged_into']])) $st->q('UPDATE summaries SET merged_into = ? WHERE id = ?', [$sums[(int)$s['merged_into']], $sums[(int)$s['id']]]);
            if ($msgs) {
                $upto = (int)($meta['kv']['summarized'] ?? 0);
                $st->set(Store::summarizedKey($space), (string)($upto ? $mapMsg($upto, false) : 0));
                $st->q('UPDATE spaces SET last_read = ? WHERE id = ?', [max($msgs), $space]);
            }

            // ---- tasks: new codes when the prefix changed, links to the moved documents, no calendar event yet
            $cards = []; $codes = [];
            foreach ($d['cards'] ?? [] as $c) {
                if (preg_match('/^[A-Z][A-Z0-9]{1,5}-(\d+)$/', (string)$c['code'], $x)) $codes[$c['code']] = "$code-{$x[1]}";
            }
            $seq = (int)($meta['kv']['card_seq'] ?? 0);
            $renum = fn(string $t) => $oldCode !== '' && $oldCode !== $code ? preg_replace_callback('/\b' . preg_quote($oldCode, '/') . '-(\d+)\b/', fn($x) => $codes[$x[0]] ?? $x[0], $t) : $t;
            foreach ($d['cards'] ?? [] as $c) {
                $old = (int)$c['id'];
                unset($c['id']);
                $newCode = $codes[$c['code']] ?? null;
                for ($n = $seq + 1; $newCode === null || $st->q('SELECT 1 FROM cards WHERE code = ?', [$newCode])->fetchColumn(); $n++) $newCode = "$code-$n";
                if ($newCode !== $c['code']) $c['old_codes'] = rtrim((string)($c['old_codes'] ?? '') ?: ',', ',') . ",{$c['code']},";
                $seq = max($seq, (int)substr($newCode, strlen($code) + 1));
                $c['code'] = $newCode;
                $c['space'] = $space;
                $c['title'] = $renum((string)$c['title']);
                $c['body'] = $refs($renum((string)$c['body']));
                $links = json_decode((string)$c['links'], true) ?: [];
                $links = array_values(array_filter(array_map(function ($l) use ($files) {
                    if (($l['type'] ?? '') !== 'file') return $l;
                    if (!isset($files[(int)($l['ref'] ?? 0)])) return null;
                    $l['ref'] = $files[(int)$l['ref']];
                    return $l;
                }, $links)));
                $c['links'] = json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $c['gcal_id'] = $c['gcal_synced'] = $c['ocal_id'] = $c['ocal_synced'] = null;
                $c['invited'] = $c['invited'] ?? null;
                $cards[$old] = $this->insert('cards', $c);
            }
            $st->set("card_seq_$code", (string)max($seq, (int)$st->get("card_seq_$code", '0')));
            foreach ($d['card_deps'] ?? [] as $x) if (isset($cards[(int)$x['card']], $cards[(int)$x['depends_on']])) $st->q('INSERT OR IGNORE INTO card_deps(card, depends_on) VALUES(?,?)', [$cards[(int)$x['card']], $cards[(int)$x['depends_on']]]);
            foreach (['card_log', 'card_time'] as $tbl) foreach ($d[$tbl] ?? [] as $x) {
                if (!isset($cards[(int)$x['card']])) continue;
                unset($x['id']); $x['card'] = $cards[(int)$x['card']];
                $this->insert($tbl, $x);
            }

            // ---- what the Mind learned in it
            $beliefs = [];
            foreach ($d['mind_beliefs'] ?? [] as $b) {
                $old = (int)$b['id'];
                unset($b['id']); $b['space'] = $space; $b['merged_into'] = null;
                $beliefs[$old] = $this->insert('mind_beliefs', $b);
            }
            foreach ($d['mind_beliefs'] ?? [] as $b) if ($b['merged_into'] && isset($beliefs[(int)$b['merged_into']])) $st->q('UPDATE mind_beliefs SET merged_into = ? WHERE id = ?', [$beliefs[(int)$b['merged_into']], $beliefs[(int)$b['id']]]);
            foreach ($d['mind_journal'] ?? [] as $j) { unset($j['id']); $j['space'] = $space; $this->insert('mind_journal', $j); }
            if (($p = (string)($meta['kv']['portrait'] ?? '')) !== '') $st->set("mind_portrait_$space", $p);
            if (($p = (string)($meta['kv']['reflected'] ?? '')) !== '') $st->set("mind_reflected_$space", $p);

            // ---- its history, with the move
            $log = json_decode((string)($meta['kv']['log'] ?? ''), true);
            $log = is_array($log) ? array_values(array_filter($log, fn($e) => is_array($e) && isset($e['ts'], $e['what']))) : [];
            $log[] = ['ts' => time(), 'what' => 'moved', 'by' => $by, 'detail' => $from];
            $st->set("space_log_$space", json_encode(array_slice($log, -30), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $st->db->commit();
        } catch (\Throwable $e) {
            $st->db->rollBack();
            foreach ($created as $p) @unlink($p);
            $zip->close();
            throw $e;
        }
        $zip->close();
        foreach ($files as $nid) if (($this->app->files()->get($nid)['status'] ?? '') === 'ready') $this->app->files()->index($nid);
        Log::info("space #$space ($name) imported from $from: " . count($msgs) . ' messages, ' . count($cards) . ' tasks, ' . count($files) . ' files');
        $out = $this->app->spaces()->get($space);
        $out['moved'] = ['messages' => count($msgs), 'tasks' => count($cards), 'routines' => count($tasks), 'jobs' => count($jobs), 'files' => count($files),
            'code_changed' => $oldCode !== '' && $oldCode !== $code, 'folder_changed' => $oldFolder !== '' && Library::normalize($oldFolder) !== $folder];
        return $out;
    }

    /**
     * Clear a space here once another assistant has it: its conversation, routines, jobs, tasks and what the Mind
     * learned in it; its folder goes to the library trash (30 days to change one's mind). Documents outside the
     * folder stay. Tasks shown in a calendar are removed from it first (the worker), then forgotten.
     */
    public function remove(int $id): void
    {
        $sp = $id ? $this->app->spaces()->get($id) : null;
        if (!$sp) throw new \InvalidArgumentException('no such space');
        $st = $this->app->store;
        $this->app->board();
        if ($st->q("SELECT 1 FROM jobs WHERE space = ? AND status IN ('queued','running')", [$id])->fetchColumn())
            throw new \InvalidArgumentException('A job of this space is still running.');
        $st->db->beginTransaction();
        try {
            $st->deleteMessages(array_column($st->q('SELECT id FROM messages WHERE space = ?', [$id])->fetchAll(), 'id'));
            foreach ($st->q('SELECT id FROM summaries WHERE space = ?', [$id])->fetchAll() as $r) $st->deleteDocs(['summary'], (int)$r['id']);
            $st->q('DELETE FROM summaries WHERE space = ?', [$id]);
            $st->q('DELETE FROM tasks WHERE space = ?', [$id]);
            foreach ($st->q('SELECT id FROM jobs WHERE space = ?', [$id])->fetchAll(\PDO::FETCH_COLUMN) as $j) $st->q('DELETE FROM tool_log WHERE job_id = ?', [$j]);
            $st->q('DELETE FROM jobs WHERE space = ?', [$id]);
            foreach ($st->q('SELECT id, gcal_id, ocal_id FROM cards WHERE space = ?', [$id])->fetchAll() as $c) {
                if ($c['gcal_id'] || $c['ocal_id']) { // out of the calendar at the next sync, then forgotten (Board::purge) a day later
                    $st->q('UPDATE cards SET space = -1, in_calendar = 0, deleted_at = ?, version = version + 1 WHERE id = ?', [time() - 29 * 86400, $c['id']]);
                    continue;
                }
                foreach (['DELETE FROM card_deps WHERE card = ? OR depends_on = ?', 'DELETE FROM card_log WHERE card = ?', 'DELETE FROM card_time WHERE card = ?', 'DELETE FROM cards WHERE id = ?'] as $sql)
                    $st->q($sql, str_contains($sql, 'OR') ? [$c['id'], $c['id']] : [$c['id']]);
            }
            $st->q('DELETE FROM mind_links WHERE belief IN (SELECT id FROM mind_beliefs WHERE space = ?)', [$id]);
            foreach (['mind_beliefs', 'mind_journal', 'mind_obs', 'notifications'] as $t) $st->q("DELETE FROM $t WHERE space = ?", [$id]);
            $st->q("DELETE FROM approvals WHERE space = ? AND status = 'pending'", [$id]);
            $st->q('UPDATE approvals SET space = 0 WHERE space = ?', [$id]);
            $st->q("UPDATE kv SET v = '0' WHERE k LIKE 'channel\\_space\\_%' ESCAPE '\\' AND v = ?", [(string)$id]);
            foreach ([Store::summarizedKey($id), "mind_portrait_$id", "mind_reflected_$id", "space_log_$id", "trouble_$id"] as $k) $st->q('DELETE FROM kv WHERE k = ?', [$k]);
            $st->q('DELETE FROM spaces WHERE id = ?', [$id]);
            if (($sp['privacy'] ?? 'internal') !== 'internal') $st->set('privacy_rev', (string)((int)$st->get('privacy_rev', '0') + 1));
            $st->db->commit();
        } catch (\Throwable $e) {
            $st->db->rollBack();
            throw $e;
        }
        if ($sp['folder'] !== '' && !Library::readonly($sp['folder']) && $this->folderUsed($sp['folder'])) {
            try { $this->app->library()->deleteFolder($sp['folder'], true); } catch (\Throwable $e) { Log::warn("space #$id moved, its folder stays: {$e->getMessage()}"); }
        }
        Log::info("space #$id ({$sp['name']}) moved to another assistant and removed here");
    }

    private function folderUsed(string $folder): bool
    {
        [$w, $a] = Library::within($folder);
        [$w2, $a2] = Library::within($folder, 'path');
        return (bool)($this->app->store->q("SELECT 1 FROM files WHERE $w AND deleted_at IS NULL LIMIT 1", $a)->fetchColumn()
            || $this->app->store->q("SELECT 1 FROM folders WHERE $w2 LIMIT 1", $a2)->fetchColumn());
    }

    /** Insert a row from a package: only the columns this database has (an older or newer Talixio may differ). */
    private function insert(string $table, array $row): int
    {
        static $cols = [];
        $cols[$table] ??= array_column($this->app->store->q("PRAGMA table_info($table)")->fetchAll(), 'name');
        $row = array_intersect_key($row, array_flip($cols[$table]));
        unset($row['id']);
        $this->app->store->q("INSERT INTO $table(" . implode(',', array_keys($row)) . ') VALUES(' . implode(',', array_fill(0, count($row), '?')) . ')',
            array_map(fn($v) => is_array($v) ? json_encode($v) : $v, array_values($row)));
        return (int)$this->app->store->db->lastInsertId();
    }

    /** File ids a text points to: links to /files/12 and "[attached pdf #12: …]". */
    public static function fileRefs(string $t): array
    {
        preg_match_all('#\]\(/?files/(\d+)|\[attached [a-z]+ \#(\d+):#', $t, $m);
        return array_values(array_unique(array_map('intval', array_filter([...$m[1], ...$m[2]]))));
    }

    public static function rewriteFileRefs(string $t, array $map): string
    {
        // a file that did not come along points nowhere: its id is another file here
        $t = preg_replace_callback('#(\]\()(/?files/)(\d+)#', fn($x) => $x[1] . (isset($map[(int)$x[3]]) ? $x[2] . $map[(int)$x[3]] : '#file-not-moved'), $t);
        return preg_replace_callback('#(\[attached [a-z]+ \#)(\d+)(:)#', fn($x) => $x[1] . ($map[(int)$x[2]] ?? '?') . $x[3], $t);
    }
}
