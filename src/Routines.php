<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The ready-made routines (morning briefing, Sunday weekly review, watch and draft), the user's 👍/👎 on what they send
 * (fed back into their next runs), and quick capture ("t: …" a task, "n: …" a note, without the model).
 */
final class Routines
{
    public const BRIEFING = 'Morning briefing';
    public const WEEKLY = 'Weekly review';
    public const WATCH_PREFIX = 'watch:'; // kv key of a watch's settings, by routine id

    public function __construct(private App $app) {}

    // ---------- quick capture ----------

    /** "t: call the bank" / "tâche: …" → ['task', text]; "n: …" / "note: …" → ['note', text]; anything else: null. */
    public static function parseCapture(string $text): ?array
    {
        if (!preg_match('/^\s*(t|task|todo|t[âa]che|n|note)\s*:\s*(\S.*)$/isu', $text, $m)) return null;
        return [in_array(mb_strtolower($m[1]), ['n', 'note'], true) ? 'note' : 'task', trim($m[2])];
    }

    /**
     * Capture a task (first line = title, the rest = description, "!1"…"!5" = priority) or a note (in the space's folder, else the Inbox).
     * @return array{kind:string,reply:string,code?:string,id?:int}
     */
    public function capture(string $kind, string $text, int $space, string $who = 'you'): array
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if ($text === '') throw new \InvalidArgumentException('Nothing to capture.');
        [$first, $rest] = array_pad(explode("\n", $text, 2), 2, '');
        if ($kind === 'task') {
            $d = ['title' => $first];
            if (preg_match('/(?:^|\s)!([1-5])(?=\s|$)/', $first, $p)) { $d['priority'] = (int)$p[1]; $d['title'] = trim(preg_replace('/(?:^|\s)![1-5](?=\s|$)/', ' ', $first)); }
            if (trim($rest) !== '') $d['body'] = trim($rest);
            $c = $this->app->board()->create($d, $space, $who);
            return ['kind' => 'task', 'code' => $c['code'], 'reply' => I18n::t($this->app, 'Task added: {code} {title} (P{p}).', ['code' => $c['code'], 'title' => $c['title'], 'p' => $c['priority']])];
        }
        $sp = $space ? $this->app->spaces()->get($space) : null;
        $folder = ($sp['folder'] ?? '') !== '' ? $sp['folder'] : Library::INBOX;
        $name = trim(mb_substr(preg_replace('/[\\\\\/:*?"<>|#\[\]]+/u', ' ', preg_replace('/^#+\s*/', '', $first)), 0, 60));
        $f = $this->app->library()->saveNote($folder, $name, $text);
        return ['kind' => 'note', 'id' => (int)$f['id'], 'reply' => I18n::t($this->app, 'Note saved: {link}', ['link' => '[' . preg_replace('/[\[\]]/', '', Library::join($f['folder'], $f['name'])) . "](/files/{$f['id']})"])];
    }

    // ---------- feedback on what routines send ----------

    /**
     * The user's 👍 (1) or 👎 (-1) on one item of a routine's message; 0 removes it. Voting again on the same item of the same message replaces the vote.
     * $task: the routine id (or 0 with $title for one found by name).
     */
    public function vote(int $task, string $item, int $vote, string $note = '', ?int $message = null, string $title = ''): ?int
    {
        $st = $this->app->store;
        $item = mb_substr(trim(preg_replace('/[ \t\r\n]+/', ' ', $item)), 0, 300); // the same as the web page does
        if ($item === '') throw new \InvalidArgumentException('Which item?');
        if ($task === 0 && $title !== '') $task = (int)$st->q('SELECT id FROM tasks WHERE title = ? COLLATE NOCASE ORDER BY id LIMIT 1', [trim($title)])->fetchColumn();
        $t = $task ? $st->task($task) : null;
        if (!$t && $task) throw new \InvalidArgumentException('no such routine');
        if ($message !== null) {
            $st->q('DELETE FROM routine_feedback WHERE message = ? AND item = ?', [$message, $item]);
            $meta = json_decode((string)$st->q("SELECT meta FROM messages WHERE id = ? AND role = 'assistant'", [$message])->fetchColumn(), true);
            if (is_array($meta)) { // shown again on the message
                if ($vote) $meta['votes'][$item] = $vote > 0 ? 1 : -1; else unset($meta['votes'][$item]);
                $st->q('UPDATE messages SET meta = ? WHERE id = ?', [json_encode($meta), $message]);
            }
        }
        if ($vote === 0) return null;
        $st->q('INSERT INTO routine_feedback(ts, task, title, item, vote, note, message) VALUES(?,?,?,?,?,?,?)',
            [time(), $task, (string)($t['title'] ?? ($title !== '' ? $title : 'Routines')), $item, $vote > 0 ? 1 : -1, mb_substr(trim($note), 0, 500), $message]);
        return (int)$st->db->lastInsertId();
    }

    /** Feedback, newest first: one routine's (with the general one, task 0), or all. */
    public function feedback(?int $task = null, int $limit = 200): array
    {
        $rows = $task === null
            ? $this->app->store->q('SELECT * FROM routine_feedback ORDER BY id DESC LIMIT ?', [$limit])->fetchAll()
            : $this->app->store->q('SELECT * FROM routine_feedback WHERE task IN (?, 0) ORDER BY id DESC LIMIT ?', [$task, $limit])->fetchAll();
        return array_map(fn($r) => ['id' => (int)$r['id'], 'ts' => (int)$r['ts'], 'task' => (int)$r['task'], 'title' => $r['title'], 'item' => $r['item'],
            'vote' => (int)$r['vote'], 'note' => $r['note'], 'message' => $r['message'] !== null ? (int)$r['message'] : null], $rows);
    }

    public function forget(int $id): bool { return $this->app->store->q('DELETE FROM routine_feedback WHERE id = ?', [$id])->rowCount() > 0; }

    /** For the routine's next run: what the user liked and didn't, so it adjusts. '' when there is none. */
    public function feedbackPrompt(int $task): string
    {
        $rows = $this->feedback($task, 40);
        if (!$rows) return '';
        $lines = array_map(fn($r) => '- ' . ($r['vote'] > 0 ? '👍' : '👎') . ' ' . date('Y-m-d', $r['ts']) . ': "' . $r['item'] . '"' . ($r['note'] !== '' ? " ({$r['note']})" : ''), array_reverse($rows));
        return "\n\n## The user's feedback on earlier runs (👍 liked, 👎 not useful)\nAdjust to it: more of what they liked, less or none of what they didn't; a recent vote counts more than an old one. Don't mention this feedback in the message.\n"
            . implode("\n", $lines);
    }

    // ---------- ready-made routines ----------

    public static function briefingPrompt(string $extra = ''): string
    {
        return "Morning briefing for the user: 5 lines at most, the most important first. Look, in this order, at:\n"
            . "1. Today's calendar (calendar_events): times, places, what to prepare or bring.\n"
            . "2. Emails waiting (mail_triage): to answer and to do first, with due dates.\n"
            . "3. Tasks (board_list with all_spaces): overdue, due today, blocked; then today's routines and reminders (list_tasks).\n"
            . "4. The weather today in the user's town (from USER.md; skip it if unknown) with a quick web search.\n"
            . "5. Anything else that matters today (follow-ups promised, birthdays in USER.md...).\n"
            . "Write only a Markdown bullet list, nothing before or after it: one line per item, starting with an emoji for its kind (📅 ✉️ ✅ ⚠️ 🌦️ 🎂 💡), "
            . "then the essential in under 20 words (time, who, what to do). Merge small things of the same kind into one line. Skip what is empty or not useful today. "
            . "In the user's language. The user can ask for details afterwards."
            . (trim($extra) !== '' ? "\nAlso: " . trim($extra) : '');
    }

    /** The morning briefing: one routine, created or updated. $days: 1-5, *, 1-6 or 0,6. */
    public function setupBriefing(string $time, string $days = '1-5', ?string $extra = null): array
    {
        [$h, $m] = self::time($time);
        $days = in_array($days, ['1-5', '*', '1-6', '0,6'], true) ? $days : '1-5';
        $id = $this->find(self::BRIEFING);
        if ($extra === null && $id) $extra = preg_match('/\nAlso: (.*)$/s', (string)$this->app->store->task($id)['prompt'], $x) ? $x[1] : '';
        return $this->upsert($id, self::BRIEFING, self::briefingPrompt((string)$extra), "$m $h * * $days", 'standard');
    }

    public static function weeklyPrompt(string $folder): string
    {
        return "Weekly review for the user, for the week that ends today. Gather:\n"
            . "1. Done this week: board_changes and board_list (all_spaces) for what was finished, and the background jobs of the week (list_jobs).\n"
            . "2. What slipped: overdue and blocked tasks (board_list with all_spaces), and why if known.\n"
            . "3. Next week: calendar_events for the next 7 days: key meetings and deadlines, what to prepare.\n"
            . "4. Emails still waiting for an answer or an action (mail_triage).\n"
            . "5. Spending of the assistant this week (usage_report), in one line.\n"
            . "Then choose the 3 priorities for next week, each with its reason in a few words.\n"
            . "Save the full review in the library with kb_save_note (folder \"$folder\", name \"Weekly review YYYY-MM-DD\" with today's date): a heading per part, short bullets, skip empty parts, in the user's language. "
            . "Then write the message for the user: only the 3 priorities as a Markdown bullet list (one line each, starting with an emoji), then one line with the link to the saved review.";
    }

    /** The Sunday weekly review. $day: 0 (Sunday) to 6. */
    public function setupWeekly(string $time, int $day = 0, string $folder = ''): array
    {
        [$h, $m] = self::time($time);
        $folder = Library::normalize($folder !== '' ? $folder : Library::NOTES . '/Weekly reviews');
        Library::guard($folder);
        return $this->upsert($this->find(self::WEEKLY), self::WEEKLY, self::weeklyPrompt($folder), "$m $h * * " . max(0, min(6, $day)), 'standard');
    }

    public static function watchPrompt(array $w): string
    {
        $span = ['daily' => '24 hours', 'weekly' => '7 days', 'monthly' => 'month'][$w['every']] ?? '7 days';
        return "Watch and draft \"{$w['name']}\". If one of your skills matches it, read it first (skill_read) and follow it.\n"
            . "Start one background job with delegate: title \"{$w['name']} – draft YYYY-MM-DD\" (today's date), folder \"{$w['folder']}\", tier standard, effort high, tags [\"watch\"]. "
            . "Its instructions must be self-contained:\n"
            . "1. Find what is new in the last $span on: {$w['topics']}" . ($w['sources'] !== '' ? "\n   Look first at these sources: {$w['sources']}" : '') . "\n"
            . "2. Keep only what really matters" . ($w['audience'] !== '' ? " for: {$w['audience']}" : '') . "; drop duplicates, rumours and promotional content.\n"
            . "3. Write the draft of the next issue" . ($w['format'] !== '' ? ", in this format: {$w['format']}" : ': a title, a 2-line intro, then 3 to 7 items (a bold title, 2-3 sentences on why it matters, the source link)') . ".\n"
            . "4. End with the list of the sources used.\n"
            . "Copy into the instructions the user's feedback below, if any (what they liked and didn't), so that the selection follows it.\n"
            . "Then tell the user in one line that the draft is being written and will be saved in {$w['folder']}.";
    }

    /** A "watch and draft" routine (e.g. the user's newsletter): settings kept so the form can show them again. $id: the routine to update. */
    public function setupWatch(array $in, ?int $id = null): array
    {
        $w = ['name' => trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? ''))), 'topics' => trim((string)($in['topics'] ?? '')), 'sources' => trim((string)($in['sources'] ?? '')),
            'audience' => trim((string)($in['audience'] ?? '')), 'format' => trim((string)($in['format'] ?? '')),
            'every' => in_array($in['every'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $in['every'] : 'weekly',
            'day' => max(0, min(6, (int)($in['day'] ?? 1))), 'time' => (string)($in['time'] ?? '08:00'), 'folder' => (string)($in['folder'] ?? '')];
        if ($w['name'] === '' || mb_strlen($w['name']) > 80) throw new \InvalidArgumentException('Give the watch a short name.');
        if ($w['topics'] === '') throw new \InvalidArgumentException('Say what to watch.');
        $w['folder'] = Library::normalize($w['folder'] !== '' ? $w['folder'] : '/Drafts/' . $w['name']);
        Library::guard($w['folder']);
        [$h, $m] = self::time($w['time']);
        $cron = match ($w['every']) { 'daily' => "$m $h * * *", 'monthly' => "$m $h 1-7 * {$w['day']}", default => "$m $h * * {$w['day']}" };
        if ($id !== null && !$this->app->store->get(self::WATCH_PREFIX . $id)) throw new \InvalidArgumentException('no such watch');
        $t = $this->upsert($id ?? $this->find($w['name']), $w['name'], self::watchPrompt($w), $cron, 'standard');
        $this->app->store->set(self::WATCH_PREFIX . $t['id'], json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $t;
    }

    /** The watches set up from the Routines page, with their settings. */
    public function watches(): array
    {
        $out = [];
        foreach ($this->app->store->q("SELECT k, v FROM kv WHERE k LIKE 'watch:%'")->fetchAll() as $r) {
            $id = (int)substr($r['k'], strlen(self::WATCH_PREFIX));
            if (!$this->app->store->task($id)) { $this->app->store->q('DELETE FROM kv WHERE k = ?', [$r['k']]); continue; } // the routine was deleted
            $out[] = ['id' => $id] + (json_decode($r['v'], true) ?: []);
        }
        return $out;
    }

    private function find(string $title): int
    {
        return (int)$this->app->store->q('SELECT id FROM tasks WHERE title = ? AND job IS NULL ORDER BY id LIMIT 1', [$title])->fetchColumn();
    }

    private function upsert(int $id, string $title, string $prompt, string $cron, string $tier): array
    {
        $s = $this->app->store;
        $next = Cron::next($cron, time());
        if ($id) $s->updateTask($id, ['title' => $title, 'cron' => $cron, 'prompt' => $prompt, 'next_run' => $next, 'enabled' => 1]);
        else $id = $s->addTask($title, $prompt, $cron, $next, $tier, 0);
        return $s->task($id);
    }

    /** "07:30" → [7, 30]. */
    private static function time(string $t): array
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($t), $m)) throw new \InvalidArgumentException('Give a time like 07:30.');
        return [(int)$m[1], (int)$m[2]];
    }
}
