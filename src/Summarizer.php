<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Compacts the conversation with progressive forgetting (fast tier):
 * - the latest messages stay word for word (Store::history);
 * - older ones are summarised in stretches (level 1, ~200 words);
 * - a past week's summaries are merged into one shorter week summary (level 2),
 *   and a past month's weeks into one even shorter month summary (level 3).
 * The unmerged summaries form the digest the assistant reads before every answer (digest()).
 * Nothing is deleted: messages and every summary stay searchable with search_memory.
 * On a Mistral account the requests go through its batch API at half the price (Batch): the summary
 * comes on a later call, within hours. $direct (the user asked for it now) answers at once.
 */
final class Summarizer
{
    public const KEEP_RECENT = 10;   // never summarised while they are the latest messages
    public const DIGEST_CHARS = 9000;

    public function __construct(private App $app, private bool $direct = false) {}

    /** One step of compaction per call (the worker calls it every few minutes). Returns true if it did something. */
    public function maybeRun(int $now, int $minMessages = 16, int $idleSeconds = 1800): bool
    {
        foreach (array_column($this->app->store->q('SELECT DISTINCT space FROM messages')->fetchAll(), 'space') as $space) { // each space has its own digest
            if ($this->compact($now, $minMessages, $idleSeconds, false, (int)$space)) return true;
        }
        return $this->merge($now);
    }

    /** Level 1: summarise messages older than the latest KEEP_RECENT, once enough have piled up or the conversation went quiet. */
    public function compact(int $now, int $minMessages = 16, int $idleSeconds = 1800, bool $force = false, int $space = 0): bool
    {
        $p = $this->app->privacy();
        $before = $this->app->space; $this->app->setSpace($space); // its level and its AI account apply to the summary request
        try {
            if ($p->modelBlocked($p->spaceLevel($space)) !== null) return false; // a secret space with a model outside: kept word for word, never summarised
            return $this->compactSpace($now, $minMessages, $idleSeconds, $force, $space);
        }
        finally { $this->app->setSpace($before); }
    }

    private function compactSpace(int $now, int $minMessages, int $idleSeconds, bool $force, int $space): bool
    {
        $store = $this->app->store;
        $from = (int)$store->get(Store::summarizedKey($space), '0');
        $last = $store->q('SELECT id, ts FROM messages WHERE space = ? ORDER BY id DESC LIMIT 1', [$space])->fetch();
        if (!$last) return false;
        $key = "compact:$space:$from";
        $sent = $this->batch($force)->meta($key); // a stretch already sent to the batch API: the same messages
        $cut = $sent !== null ? (int)($sent['to'] ?? 0) : (int)($store->q('SELECT id FROM messages WHERE space = ? ORDER BY id DESC LIMIT 1 OFFSET ?', [$space, self::KEEP_RECENT])->fetchColumn() ?: 0);
        $idle = (int)$last['ts'] <= $now - $idleSeconds;
        $rows = $store->q('SELECT * FROM messages WHERE space = ? AND id > ? AND id <= ? ORDER BY id LIMIT 120', [$space, $from, $cut])->fetchAll();
        if (!$rows) { if ($sent !== null) $this->batch($force)->drop($key); return false; }
        if ($sent === null && !$force && count($rows) < $minMessages && !($idle && count($rows) >= 4)) return false;
        $transcript = '';
        foreach ($rows as $r) {
            $transcript .= sprintf("[%s] %s (%s): %s\n", date('D Y-m-d H:i', (int)$r['ts']), $r['role'], $r['channel'], mb_substr($r['content'], 0, 1500));
        }
        $text = $this->ask($force, $key, ['to' => (int)end($rows)['id']], "Summarise this stretch of conversation for the assistant's memory. Keep facts about the user, decisions, commitments and promises (with dates), "
            . "things to follow up, and useful results (with links). Skip small talk. Max 200 words, bullet points.\n\n$transcript");
        if ($text === null || $text === false) return false; // false: waiting for the batch
        $store->addSummary((int)$rows[0]['id'], (int)end($rows)['id'], (int)$rows[0]['ts'], (int)end($rows)['ts'], $text, 1, $space);
        $store->set(Store::summarizedKey($space), (string)end($rows)['id']);
        $mind = $this->app->mind(); // the same stretch, read again for what it says about the user and the assistant
        if ($mind->enabled()) { try { $mind->observe($rows, $space); } catch (\Throwable $e) { Log::warn("mind: {$e->getMessage()}"); } }
        return true;
    }

    /** Levels 2 and 3: merge the summaries of a finished week (after 3 days) or month (after 2 weeks), one merge per call. */
    public function merge(int $now): bool
    {
        $store = $this->app->store;
        foreach ([[1, 'week'], [2, 'month']] as [$level, $period]) {
            $groups = [];
            foreach ($store->q('SELECT * FROM summaries WHERE level = ? AND merged_into IS NULL ORDER BY ts_from', [$level])->fetchAll() as $s) {
                [$start, $end] = self::period($period, (int)$s['ts_from']);
                if ($end > $now - ($period === 'week' ? 3 : 14) * 86400) continue; // too recent: keep the details a bit longer
                $groups[$s['space'] . ':' . $start][] = $s;
            }
            foreach ($groups as $items) {
                $space = (int)$items[0]['space'];
                $start = self::period($period, (int)$items[0]['ts_from'])[0];
                $ids = array_map(fn($s) => (int)$s['id'], $items);
                $key = "merge:$space:$level:$start";
                if (($sent = $this->batch()->meta($key)) !== null && ($sent['ids'] ?? []) !== $ids) $this->batch()->drop($key); // the week changed since: asked again
                $label = $period === 'week' ? 'the week of ' . date('D j M Y', $start) : date('F Y', $start);
                $notes = implode("\n\n", array_map(fn($s) => '(' . date('D j M', (int)$s['ts_from']) . ")\n" . $s['text'], $items));
                $p = $this->app->privacy();
                $before = $this->app->space; $this->app->setSpace($space); // the space's level and AI account, as for its level-1 summaries
                try {
                    if ($p->modelBlocked($p->spaceLevel($space)) !== null) continue;
                    $text = $this->ask(false, $key, ['ids' => $ids], "These are notes from the assistant's conversations with the user during $label. Condense them into one summary of at most "
                    . ($period === 'week' ? '150' : '120') . " words, bullet points. Keep what may still matter later: decisions, commitments and deadlines (with dates), "
                    . "facts about the user and their projects, open follow-ups, results worth finding again. Drop what is finished and no longer matters, and small talk."
                    . "\n\n$notes");
                } finally { $this->app->setSpace($before); }
                if ($text === false) continue; // waiting for the batch: the next group meanwhile
                if ($text === null) return false;
                $id = $store->addSummary((int)$items[0]['from_id'], (int)end($items)['to_id'], (int)$items[0]['ts_from'], (int)end($items)['ts_to'], $text, $level + 1, $space);
                $store->q('UPDATE summaries SET merged_into = ? WHERE id IN (' . implode(',', $ids) . ')', [$id]);
                return true;
            }
        }
        return false;
    }

    /** What the assistant reads before every answer: month, week and recent summaries not yet merged, oldest first, within a size limit. */
    public function digest(int $space = 0): string
    {
        $rows = $this->app->store->q('SELECT * FROM summaries WHERE merged_into IS NULL AND space = ? ORDER BY ts_from DESC LIMIT 60', [$space])->fetchAll();
        $parts = []; $size = 0;
        foreach ($rows as $s) { // newest first, so the oldest are the ones left out
            $head = match ((int)$s['level']) {
                3 => date('F Y', (int)$s['ts_from']),
                2 => 'Week of ' . date('D j M Y', self::period('week', (int)$s['ts_from'])[0]),
                default => date('D j M Y H:i', (int)$s['ts_from']) . ((int)$s['ts_to'] - (int)$s['ts_from'] > 3600 ? ' to ' . date(date('Y-m-d', (int)$s['ts_to']) === date('Y-m-d', (int)$s['ts_from']) ? 'H:i' : 'D j M H:i', (int)$s['ts_to']) : ''),
            };
            $block = "### $head\n" . trim($s['text']);
            if ($size + mb_strlen($block) > self::DIGEST_CHARS && $parts) break;
            $parts[] = $block; $size += mb_strlen($block);
        }
        return implode("\n\n", array_reverse($parts));
    }

    /** [start, end) of the calendar week (Monday) or month containing $ts, local time. */
    public static function period(string $period, int $ts): array
    {
        if ($period === 'week') { $start = strtotime('monday this week 00:00', $ts); return [$start, strtotime('+1 week', $start)]; }
        $start = strtotime(date('Y-m-01 00:00', $ts)); return [$start, strtotime('+1 month', $start)];
    }

    /** The summary, null when there is none, false while the batch runs. */
    private function ask(bool $force, string $key, array $meta, string $prompt): string|false|null
    {
        [$state, $text] = $this->batch($force)->ask($key, 'summary', 'summary', 'fast', $prompt, $meta);
        return $state === 'waiting' ? false : $text;
    }

    private function batch(bool $force = false): Batch { return new Batch($this->app, $this->direct || $force); }
}
