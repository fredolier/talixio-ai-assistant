<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Signal channel through signal-cli (https://github.com/AsamK/signal-cli), registered to a number
 * dedicated to the assistant. Messages are pulled by the worker every few seconds.
 */
final class Signal
{
    public function __construct(private App $app) {}

    public function enabled(): bool { return $this->app->cfg->signalNumber !== '' && $this->app->cfg->signalAllowed !== []; }

    /** @return array{0:int,1:string,2:string} exit code, stdout, stderr */
    private function cli(array $args, int $timeout = 60): array
    {
        $c = $this->app->cfg;
        $cmd = array_merge([$c->signalCli, '--config', $c->dataDir . '/signal', '-a', $c->signalNumber], $args);
        $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) throw new \RuntimeException("cannot run {$c->signalCli}");
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = $err = ''; $deadline = microtime(true) + $timeout;
        while (true) {
            $out .= (string)stream_get_contents($pipes[1]); $err .= (string)stream_get_contents($pipes[2]);
            $st = proc_get_status($p);
            if (!$st['running']) { $out .= (string)stream_get_contents($pipes[1]); $err .= (string)stream_get_contents($pipes[2]); break; }
            if (microtime(true) > $deadline) { proc_terminate($p); throw new \RuntimeException('signal-cli timed out'); }
            usleep(50_000);
        }
        proc_close($p);
        return [$st['exitcode'], $out, $err];
    }

    public function send(string $to, string $text): void
    {
        $plain = preg_replace(['/\*\*([^*]+)\*\*/', '/`([^`]+)`/', '/\[([^\]]+)\]\((https?:[^)]+)\)/'], ['$1', '$1', '$1 ($2)'], $text);
        [$code, , $err] = $this->cli(['send', '-m', $plain, $to]);
        if ($code !== 0) throw new \RuntimeException('signal send failed: ' . mb_substr(trim($err), 0, 300));
    }

    public function notify(string $title, string $text): void
    {
        if ($this->enabled()) $this->send($this->app->cfg->signalAllowed[0], $text);
    }

    /** Worker tick: receive and answer. Returns how many messages were answered. */
    public function poll(): int
    {
        if (!$this->enabled()) return 0;
        [$code, $out, $err] = $this->cli(['--output=json', 'receive', '--timeout', '1'], 90);
        if ($code !== 0) throw new \RuntimeException('signal receive failed: ' . mb_substr(trim($err), 0, 300));
        $n = 0;
        foreach (array_filter(explode("\n", $out)) as $line) {
            $env = json_decode($line, true)['envelope'] ?? null;
            $text = trim((string)($env['dataMessage']['message'] ?? ''));
            $from = (string)($env['sourceNumber'] ?? $env['source'] ?? '');
            if ($text === '' || $from === '') continue;
            if (!in_array($from, $this->app->cfg->signalAllowed, true)) { Log::warn("signal: ignored message from $from (not in TALIXIO_SIGNAL_ALLOWED)"); continue; }
            $this->send($from, $this->app->agent()->chat('signal', $text));
            $n++;
        }
        return $n;
    }
}
