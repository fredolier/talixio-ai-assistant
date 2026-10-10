<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Actions with outside effects (sending an email, adding to your calendar, submitting a web form)
 * wait for your approval: buttons in the web app, or "yes 12" / "no 12" in any chat channel.
 */
final class Approvals
{
    public function __construct(private App $app) {}

    /** Called instead of running a sensitive tool. Returns what the model is told. */
    public function request(string $tool, array $args, string $summary): string
    {
        $id = $this->create($tool, $args, $summary, 'deferred');
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

    private function create(string $tool, array $args, string $summary, string $mode): int
    {
        $st = $this->app->store;
        $st->q('INSERT INTO approvals(tool, args, summary, status, created, mode, space) VALUES(?,?,?,?,?,?,?)',
            [$tool, json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $summary, 'pending', time(), $mode, $this->app->space]);
        $id = (int)$st->db->lastInsertId();
        try {
            $this->app->notifier()->notify('Approval needed', "$summary\n\nApprove in the app, or reply \"yes $id\" (or \"no $id\").", $this->app->space);
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
        $result = $this->app->tools->execute($a['tool'], json_decode($a['args'], true) ?: []);
        $ok = !str_starts_with($result, 'error');
        $st->q('UPDATE approvals SET result=?, status=? WHERE id=?', [mb_substr($result, 0, 4000), $ok ? 'done' : 'failed', $id]);
        $msg = I18n::t($this->app, $ok ? 'Done: {what}' : 'Failed: {what}', ['what' => $a['summary']]) . "\n\n" . mb_substr($result, 0, 600);
        $st->addMessage('assistant', 'approval', $msg, null, [], (int)$a['space']);
        return $msg;
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
