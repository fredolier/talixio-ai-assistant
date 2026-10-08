<?php
declare(strict_types=1);
namespace Talixio;

/** Long-polling bot (no public URL needed) plus a UTC daily scheduler, in one loop. */
final class Telegram
{
    private string $api;
    private array $lastFired = [];

    public function __construct(private Config $cfg, private Agent $agent)
    {
        $this->api = "https://api.telegram.org/bot{$cfg->telegramToken}";
    }

    public function send(int $chat, string $text): void
    {
        foreach (mb_str_split($text, 4000) as $part) { // Telegram limit is 4096
            Http::json("{$this->api}/sendMessage", ['chat_id' => $chat, 'text' => $part], [], 30);
        }
    }

    private function answer(int $chat, string $text): void
    {
        try { $this->send($chat, $this->agent->reply((string)$chat, $text)); }
        catch (\Throwable $e) {
            Log::error('handler failed: ' . $e->getMessage());
            try { $this->send($chat, 'Sorry, something went wrong.'); } catch (\Throwable) {}
        }
    }

    private function tickScheduler(): void
    {
        $hhmm = gmdate('H:i'); $day = gmdate('Y-m-d');
        foreach ($this->cfg->dailyJobs as $i => [$when, $chat, $prompt]) {
            if ($when === $hhmm && ($this->lastFired[$i] ?? '') !== $day) {
                $this->lastFired[$i] = $day;
                $this->answer($chat, $prompt);
            }
        }
    }

    public function run(): never
    {
        $offset = 0;
        while (true) {
            try {
                [, $body] = Http::request('GET', "{$this->api}/getUpdates?timeout=25&offset=$offset", [], null, 40);
                foreach ((json_decode($body, true)['result'] ?? []) as $u) {
                    $offset = $u['update_id'] + 1;
                    $chat = $u['message']['chat']['id'] ?? null;
                    $text = $u['message']['text'] ?? null;
                    if ($chat === null || $text === null) continue;
                    if ($this->cfg->telegramAllowed && !in_array($chat, $this->cfg->telegramAllowed, true)) {
                        Log::warn("ignored unauthorized chat $chat");
                        continue;
                    }
                    $this->answer($chat, $text);
                }
            } catch (\Throwable $e) {
                Log::error('poll failed: ' . $e->getMessage());
                sleep(5);
            }
            $this->tickScheduler();
        }
    }
}
