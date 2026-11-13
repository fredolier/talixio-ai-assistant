<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Actions with outside effects (sending an email, adding to your calendar, submitting a web form)
 * wait for your approval: buttons in the web app, or "yes 12" / "no 12" in any chat channel.
 * A bare "yes" answers only when one request is pending from that same channel (or from an unattended run).
 */
final class Approvals
{
    public function __construct(private App $app) {}

    /** Called instead of running a sensitive tool. Returns what the model is told. */
    public function request(string $tool, array $args, string $summary, int $routine = 0): string
    {
        $id = $this->create($tool, $args, $summary, 'deferred', $routine);
        return "Waiting for the user's approval (request #$id): $summary. Nothing has been done yet; it will run only if they approve. Tell the user briefly what you're asking to do.";
    }

    /**
     * For actions that must happen now in a live session (a browser form): ask, then wait for the answer.
     * Returns true if approved within the time limit.
     */
    public function ask(string $tool, array $args, string $summary): bool
    {
        $id = $this->create($tool, $args, $summary, 'inline');
        $deadline = time() + $this->app->cfg->approvalWaitSeconds;
        while (time() < $deadline) {
            $a = $this->get($id);
            if ($a['status'] !== 'pending') return $a['status'] === 'approved';
            sleep(2);
        }
        $this->app->store->q("UPDATE approvals SET status='expired', decided=? WHERE id=? AND status='pending'", [time(), $id]);
        return false;
    }

    private function create(string $tool, array $args, string $summary, string $mode, int $routine = 0): int
    {
        $st = $this->app->store;
        $st->q('INSERT INTO approvals(tool, args, summary, status, created, mode, space, channel, routine) VALUES(?,?,?,?,?,?,?,?,?)',
            [$tool, json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $summary, 'pending', time(), $mode, $this->app->space, $this->app->channel, $routine]);
        $id = (int)$st->db->lastInsertId();
        // one notice per batch: no new email or message while an earlier request (last 30 minutes) still waits
        $waiting = (int)$st->q("SELECT COUNT(*) FROM approvals WHERE status='pending' AND id < ? AND created > ?", [$id, time() - 1800])->fetchColumn();
        if ($waiting) return $id;
        try {
            // away from the app, the notice is all there is to judge by: the arguments go with it (the summary shortens long text)
            $json = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $details = $args && mb_strlen($json) > 200 ? "\n\nDetails:\n" . mb_substr($json, 0, 1500) . (mb_strlen($json) > 1500 ? ' […]' : '') : '';
            $this->app->notifier()->notify('Approval needed', "$summary$details\n\nApprove in the app, or reply \"yes $id\" (or \"no $id\").", $this->app->space);
        } catch (\Throwable $e) {
            Log::warn("approval notify: {$e->getMessage()}");
        }
        return $id;
    }

    public function pending(): array
    {
        $this->app->store->q("UPDATE approvals SET status='expired', decided=? WHERE status='pending' AND created < ?", [time(), time() - 7 * 86400]);
        return $this->app->store->q("SELECT * FROM approvals WHERE status='pending' ORDER BY id")->fetchAll();
    }

    public function get(int $id): ?array { return $this->app->store->q('SELECT * FROM approvals WHERE id=?', [$id])->fetch() ?: null; }

    /** Approve (run the action) or reject. Records the outcome in the conversation. Returns a short message. */
    public function decide(int $id, bool $approve, string $channel = 'web'): string
    {
        $st = $this->app->store;
        $a = $this->get($id);
        if (!$a) return I18n::t($this->app, "There's no approval request #{id}.", ['id' => $id]);
        if ($a['status'] !== 'pending') return I18n::t($this->app, 'Request #{id} was already {status}.', ['id' => $id, 'status' => $a['status']]);
        // claim it atomically so two clicks can't run it twice
        if ($st->q("UPDATE approvals SET status=?, decided=? WHERE id=? AND status='pending'", [$approve ? 'approved' : 'rejected', time(), $id])->rowCount() !== 1) {
            return I18n::t($this->app, 'Request #{id} was already handled.', ['id' => $id]);
        }
        if (!$approve) {
            $msg = I18n::t($this->app, 'Cancelled: {what}', ['what' => $a['summary']]);
            $st->addMessage('assistant', 'approval', $msg, null, [], (int)$a['space']);
            return $msg;
        }
        if ($a['mode'] === 'inline') return I18n::t($this->app, 'Approved: {what}. Continuing.', ['what' => $a['summary']]); // the waiting session carries on
        $before = $this->app->space; $this->app->setSpace((int)$a['space']);
        $was = Privacy::snapshot(); Privacy::trustAgain(); // what this one action reads is recorded with its result
        try { $result = Privacy::approved(fn() => $this->app->tools->execute($a['tool'], json_decode($a['args'], true) ?: [])); } // you approved this one action
        finally { $read = Privacy::snapshot(); Privacy::trustAgain(); Privacy::adopt($was); $this->app->setSpace($before); }
        $ok = !str_starts_with($result, 'error');
        $st->q('UPDATE approvals SET result=?, status=? WHERE id=?', [mb_substr($result, 0, 20000), $ok ? 'done' : 'failed', $id]);
        $head = I18n::t($this->app, $ok ? 'Done: {what}' : 'Failed: {what}', ['what' => $a['summary']]);
        $this->record($a, $read['untrusted'] ? ['untrusted' => true, 'private' => $read['private'], 'links' => $read['links']] : null);
        return $head . "\n\n" . mb_substr($result, 0, 600) . (mb_strlen($result) > 600 ? ' […]' : ''); // a chat app gets the start
    }

    /**
     * The result in the conversation, kept whole (the assistant reads it when asked about it). Approvals decided one
     * after the other go into one message: the latest message, if it holds results of approvals from the last 30
     * minutes of the same routine (or none), is replaced by one holding them all; nine approved notes made nine
     * messages before (1.35.51). A page, an email or a connector's result replayed in the next turns is still
     * content written by others (Agent::chat): the trust goes with the message.
     */
    private function record(array $a, ?array $trust): void
    {
        $st = $this->app->store; $space = (int)$a['space']; $routine = (int)($a['routine'] ?? 0);
        $last = $st->q('SELECT * FROM messages WHERE space = ? ORDER BY id DESC LIMIT 1', [$space])->fetch() ?: null;
        $lm = $last ? (json_decode((string)$last['meta'], true) ?: []) : [];
        $ids = $last && $last['channel'] === 'approval' && is_array($lm['approvals'] ?? null) && (int)$last['ts'] > time() - 1800
            && (int)($lm['routine_approvals'] ?? 0) === $routine ? array_map('intval', $lm['approvals']) : [];
        $ids[] = (int)$a['id'];
        $rows = array_values(array_filter(array_map(fn($i) => $this->get($i), $ids)));
        $line = fn(array $r) => I18n::t($this->app, $r['status'] === 'done' ? 'Done: {what}' : 'Failed: {what}', ['what' => $r['summary']]);
        if (count($rows) === 1) $content = $line($rows[0]) . "\n\n" . self::block((string)$rows[0]['result'], 20000);
        else {
            $failed = count(array_filter($rows, fn($r) => $r['status'] !== 'done'));
            $title = $routine ? ($st->task($routine)['title'] ?? '') : '';
            $head = I18n::t($this->app, $failed ? '{n} approved actions, {failed} failed' : 'Done: {n} approved actions', ['n' => count($rows), 'failed' => $failed])
                . ($title !== '' ? ' ' . I18n::t($this->app, '(routine "{title}")', ['title' => $title]) : '');
            $content = $head . "\n\n" . implode("\n\n---\n\n", array_map(fn($r) => '**' . str_replace("\n", ' ', $line($r)) . "**\n\n" . self::block((string)$r['result'], 4000), $rows));
        }
        $trust = Privacy::merge([$lm['trust'] ?? null, $trust]);
        $meta = ['approvals' => $ids] + ($routine ? ['routine_approvals' => $routine] : []) + ($trust ? ['trust' => $trust] : []);
        if (count($ids) > 1) $meta['replaces'] = [(int)$last['id']];
        $st->addMessage('assistant', 'approval', $content, $meta, [], $space);
        // added first: deleting the latest message first would give the new one its id again, and the app would skip it
        if (count($ids) > 1) $st->deleteMessages([(int)$last['id']]);
    }

    /**
     * "Always allow": trust the site of a page request (or web search from Confidential spaces) for good, or let the
     * routine that asked do this again without asking after reading the web (Routines::allows), then approve.
     */
    public function always(int $id, string $channel = 'web'): string
    {
        $a = $this->get($id);
        $args = json_decode((string)($a['args'] ?? ''), true) ?: [];
        if ($a && $a['status'] === 'pending') {
            $c = $this->app->cfg;
            if (in_array($a['tool'], ['fetch_url', 'browser_open'], true) && ($site = Config::siteOf((string)($args['url'] ?? ''))) !== '') {
                $c->webTrusted = array_values(array_unique([...$c->webTrusted, $site]));
                Settings::save($c->dataDir, ['TALIXIO_WEB_TRUSTED' => implode(',', $c->webTrusted)]);
            } elseif ($a['tool'] === 'web_search') {
                $c->webSearchTrusted = true;
                Settings::save($c->dataDir, ['TALIXIO_WEB_SEARCH_TRUSTED' => '1']);
            } elseif ((int)($a['routine'] ?? 0) && Routines::grantable($a['tool'])) {
                $this->app->routines()->grant((int)$a['routine'], $a['tool'], $args);
            }
        }
        return $this->decide($id, true, $channel);
    }

    /** A tool's result for the conversation: JSON indented in a code block, other text as is; very long ones cut. */
    public static function block(string $result, int $max): string
    {
        $data = json_decode($result, true);
        if (is_array($data)) $result = (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cut = mb_strlen($result) > $max;
        $result = $cut ? mb_substr($result, 0, $max) : $result;
        return (is_array($data) ? "```json\n" . str_replace('```', "``\u{200B}`", $result) . "\n```" : $result) . ($cut ? "\n[… cut at $max characters]" : '');
    }

    /** "yes", "oui 12", "no #3"... Returns [approve, id|null] or null if the text isn't an answer. */
    public static function parseReply(string $text): ?array
    {
        if (!preg_match('/^\s*(yes|y|oui|ok|okay|approve|go|vas-y|no|n|non|reject|cancel|annule)\b[\s#.!]*(\d+)?\s*[.!]?\s*$/iu', $text, $m)) return null;
        $word = mb_strtolower($m[1]);
        $id = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : null;
        if ($id === null && !in_array($word, ['yes', 'oui', 'approve', 'no', 'non', 'reject'], true)) return null; // "ok" alone is too vague
        return [in_array($word, ['yes', 'y', 'oui', 'ok', 'okay', 'approve', 'go', 'vas-y'], true), $id];
    }
}
