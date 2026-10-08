<?php
declare(strict_types=1);
namespace Talixio;

/** Turns finished stretches of conversation into short dated summaries, indexed for search_memory. Uses the fast tier. */
final class Summarizer
{
    public function __construct(private App $app) {}

    public function maybeRun(int $now, int $minMessages = 12, int $idleSeconds = 1800): bool
    {
        $store = $this->app->store;
        $from = (int)$store->get('summarized_upto', '0');
        $rows = $store->q('SELECT * FROM messages WHERE id > ? ORDER BY id LIMIT 120', [$from])->fetchAll();
        if (count($rows) < $minMessages) return false;
        if (count($rows) < 120 && (int)end($rows)['ts'] > $now - $idleSeconds) return false; // still talking
        $transcript = '';
        foreach ($rows as $r) {
            $transcript .= sprintf("[%s] %s (%s): %s\n", date('Y-m-d H:i', (int)$r['ts']), $r['role'], $r['channel'], mb_substr($r['content'], 0, 1500));
        }
        $ask = "Summarise this conversation for the assistant's long-term memory. Keep facts about the user, decisions, commitments and promises (with dates), "
            . "things to follow up, and useful results (with links). Skip small talk. Max 200 words, bullet points.\n\n$transcript";
        try {
            [$text] = $this->app->agent()->run('summary', 'fast', 'none', [['role' => 'user', 'content' => $ask]], '', 'summary');
        } catch (Abort) {
            return false;
        }
        if ($text === '') return false;
        $store->addSummary((int)$rows[0]['id'], (int)end($rows)['id'], (int)$rows[0]['ts'], (int)end($rows)['ts'], $text);
        $store->set('summarized_upto', (string)end($rows)['id']);
        return true;
    }
}
