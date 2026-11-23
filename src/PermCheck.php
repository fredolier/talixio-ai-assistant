<?php
declare(strict_types=1);

namespace Talixio;

/**
 * What a coding agent's permission request does, read without a model (1.53.0): "read" (it only reads: safe to allow),
 * "risk" with a reason (deletes, network, push, install, a script the card cannot show…), or "" (it changes things in
 * its project: your call). And which of Claude Code's "always allow" suggestions are narrow enough to save from the
 * board. It only labels: nothing is allowed without your click, and a rule is never saved without it.
 */
final class PermCheck
{
    /** Commands that only read (with the exceptions handled below). */
    private const READ = ['ls', 'cat', 'head', 'tail', 'grep', 'egrep', 'fgrep', 'rg', 'find', 'wc', 'diff', 'stat', 'du', 'df', 'echo',
        'printf', 'pwd', 'which', 'whereis', 'type', 'file', 'tree', 'realpath', 'readlink', 'basename', 'dirname', 'date', 'sort',
        'uniq', 'cut', 'tr', 'jq', 'sha256sum', 'sha1sum', 'md5sum', 'cksum', 'nl', 'column', 'true', 'false', 'test', 'cd', 'sed', 'id',
        'uname', 'whoami', 'hostname', 'env', 'printenv', 'ps', 'lsof', 'comm', 'cmp', 'zcat', 'xxd', 'od', 'hexdump', 'strings'];
    /** git subcommands that only read. */
    private const GIT_READ = ['status', 'diff', 'log', 'show', 'branch', 'rev-parse', 'ls-files', 'blame', 'grep', 'describe', 'shortlog', 'remote', 'reflog', 'ls-tree', 'cat-file'];
    /** Wrappers Claude Code itself looks past (its permissions documentation). */
    private const WRAP = ['timeout', 'time', 'nice', 'nohup', 'stdbuf', 'command', 'builtin', 'noglob'];
    /** A rule on one of these alone would allow anything it runs. */
    private const BROAD = ['sh', 'bash', 'zsh', 'dash', 'python', 'python3', 'php', 'node', 'deno', 'bun', 'perl', 'ruby', 'npx', 'npm', 'pnpm',
        'yarn', 'git', 'sudo', 'env', 'xargs', 'make', 'docker', 'kubectl', 'eval', 'exec', 'source', '.', 'find', 'sed', 'awk', 'cd', 'uv', 'poetry', 'bundle'];
    private const BROAD2 = ['npm run', 'npm exec', 'pnpm run', 'pnpm exec', 'pnpm dlx', 'yarn run', 'yarn dlx', 'uv run', 'poetry run', 'bundle exec',
        'docker exec', 'docker run', 'kubectl exec', 'git -c', 'git -C'];
    /** The tools a rule may be saved for from the board. */
    private const RULE_TOOLS = ['Bash', 'Edit', 'Write', 'MultiEdit', 'NotebookEdit', 'Read', 'Glob', 'Grep', 'WebFetch'];

    /**
     * ['level' => 'read'|'risk'|'', 'why' => a reason code when risk]: delete, network, git, install, script, admin, settings,
     * deploy, hidden (code the card cannot show: $(…), sh -c, eval).
     */
    public static function assess(string $tool, array $in): array
    {
        if (in_array($tool, ['Read', 'Glob', 'Grep', 'LS', 'NotebookRead'], true)) return ['level' => 'read', 'why' => ''];
        if (in_array($tool, ['Edit', 'Write', 'MultiEdit', 'NotebookEdit'], true)) {
            $p = (string)($in['file_path'] ?? $in['notebook_path'] ?? '');
            return preg_match('#(^|/)\.claude/settings[^/]*\.json$#', $p) ? ['level' => 'risk', 'why' => 'settings'] : ['level' => '', 'why' => ''];
        }
        if ($tool !== 'Bash') return ['level' => '', 'why' => ''];
        return self::bash((string)($in['command'] ?? ''));
    }

    public static function bash(string $cmd): array
    {
        $parts = self::split($cmd);
        if ($parts === null) return ['level' => 'risk', 'why' => 'hidden'];
        $read = $parts !== [];
        foreach ($parts as [$seg, $writes]) {
            $w = self::words($seg);
            if (!$w) continue;
            $why = self::risk($w);
            if ($why !== '') return ['level' => 'risk', 'why' => $why];
            if ($writes || !self::readOnly($w)) $read = false;
        }
        return ['level' => $read ? 'read' : '', 'why' => ''];
    }

    /**
     * The command cut at its shell operators (&&, ||, ;, |, &, newlines) outside quotes: [[segment, writes a file], …];
     * null when it holds code the card cannot show ($(…), backticks).
     */
    private static function split(string $cmd): ?array
    {
        $out = []; $cur = ''; $q = ''; $writes = false; $n = strlen($cmd);
        for ($i = 0; $i < $n; $i++) {
            $c = $cmd[$i];
            if ($q === "'") { if ($c === "'") $q = ''; $cur .= $c; continue; }
            if ($c === '\\' && $i + 1 < $n) { $cur .= $c . $cmd[++$i]; continue; }
            if ($q === '"') {
                if ($c === '"') $q = '';
                elseif ($c === '`' || ($c === '$' && ($cmd[$i + 1] ?? '') === '(')) return null;
                $cur .= $c; continue;
            }
            if ($c === "'" || $c === '"') { $q = $c; $cur .= $c; continue; }
            if ($c === '`' || ($c === '$' && ($cmd[$i + 1] ?? '') === '(') || ($c === '(' && trim($cur) === '') ) return null;
            if ($c === '>' || $c === '<' && ($cmd[$i + 1] ?? '') === '(') {
                if ($c === '<') return null; // <(…)
                // 2>&1, >/dev/null, 2>/dev/null are fine; any other > writes a file
                $rest = substr($cmd, $i + 1);
                if (preg_match('#^>?&[0-9]#', $rest) || preg_match('#^>?\s*/dev/null\b#', $rest)) { $cur .= $c; continue; }
                $writes = true; $cur .= $c; continue;
            }
            if ($c === ';' || $c === "\n" || $c === '|' || $c === '&') {
                if ($c === '&' && substr($cur, -1) === '>') { $cur .= $c; continue; } // the & of 2>&1
                if (trim($cur) !== '') $out[] = [trim($cur), $writes];
                $cur = ''; $writes = false;
                if (($c === '|' || $c === '&') && ($cmd[$i + 1] ?? '') === $c) $i++;
                continue;
            }
            $cur .= $c;
        }
        if ($q !== '') return null;
        if (trim($cur) !== '') $out[] = [trim($cur), $writes];
        return $out;
    }

    /** The segment's words, quotes removed, past leading VAR=value and the wrappers Claude Code looks past. */
    private static function words(string $seg): array
    {
        preg_match_all('/"(?:\\\\.|[^"\\\\])*"|\'[^\']*\'|\S+/', $seg, $m);
        $w = array_map(fn($x) => ($x[0] === '"' || $x[0] === "'") ? substr($x, 1, -1) : $x, $m[0]);
        while ($w && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $w[0])) array_shift($w);
        while ($w && in_array($w[0], self::WRAP, true)) {
            $wrap = array_shift($w);
            while ($w && ($w[0][0] === '-' || ($wrap === 'timeout' && preg_match('/^\d+(\.\d+)?[smhd]?$/', $w[0])) || ($wrap === 'nice' && is_numeric($w[0])))) array_shift($w);
        }
        if ($w && $w[0] === 'xargs' && count($w) > 1 && $w[1][0] !== '-') array_shift($w);
        return $w;
    }

    private static function risk(array $w): string
    {
        $c = basename($w[0]); $args = array_slice($w, 1); $line = ' ' . implode(' ', $w) . ' ';
        if (in_array($c, ['sudo', 'su', 'doas'], true)) return 'admin';
        if (in_array($c, ['rm', 'rmdir', 'shred', 'unlink', 'dd', 'mkfs', 'truncate'], true)) return 'delete';
        if ($c === 'find' && array_intersect($args, ['-delete', '-exec', '-execdir', '-ok', '-okdir'])) return 'delete';
        if (in_array($c, ['curl', 'wget', 'ssh', 'scp', 'rsync', 'sftp', 'ftp', 'nc', 'ncat', 'telnet'], true)) return 'network';
        if (in_array($c, ['sh', 'bash', 'zsh', 'dash', 'eval', 'source', '.'], true)) {
            if (in_array('-c', $args, true) || $c === 'eval') return 'hidden';
            return 'script';
        }
        if (in_array($c, ['python', 'python3', 'node', 'perl', 'ruby', 'php'], true) && array_intersect($args, ['-c', '-e', '-r'])) return 'hidden';
        if (preg_match('#^(/tmp/|/var/tmp/|/dev/shm/)#', $w[0]) || preg_grep('#^(/tmp/|/var/tmp/)\S*\.(sh|bash|py|php|js|pl)$#', $args)) return 'script';
        if ($c === 'git') {
            $sub = self::gitSub($args);
            if (in_array($sub, ['push', 'filter-branch', 'filter-repo'], true)) return 'git';
            if ($sub === 'reset' && str_contains($line, ' --hard')) return 'git';
            if ($sub === 'clean' || ($sub === 'checkout' && str_contains($line, ' -- ')) || ($sub === 'branch' && preg_match('/ -(D|-delete)\b/', $line))) return 'delete';
            if ($sub === 'rebase' || ($sub === 'commit' && str_contains($line, ' --amend'))) return 'git';
        }
        if (in_array($c, ['npm', 'pnpm', 'yarn', 'bun', 'pip', 'pip3', 'uv', 'poetry', 'gem', 'cargo', 'go', 'composer', 'apt', 'apt-get', 'brew', 'snap', 'dnf', 'yum', 'apk'], true)
            && array_intersect($args, ['install', 'i', 'add', 'publish', 'remove', 'uninstall', 'upgrade', 'update', 'ci'])) return in_array('publish', $args, true) ? 'deploy' : 'install';
        if (in_array($c, ['chmod', 'chown', 'kill', 'pkill', 'killall', 'reboot', 'shutdown', 'systemctl', 'crontab'], true)) return 'admin';
        if ($c !== 'git' && !in_array($c, self::READ, true) && preg_grep('/^(deploy|release|publish)([:_-]\S*)?$/i', $args)) return 'deploy';
        return '';
    }

    private static function gitSub(array $args): string
    {
        for ($i = 0; $i < count($args); $i++) {
            if (in_array($args[$i], ['-C', '-c', '--git-dir', '--work-tree'], true)) { $i++; continue; }
            if ($args[$i] !== '' && $args[$i][0] === '-') continue;
            return $args[$i];
        }
        return '';
    }

    private static function readOnly(array $w): bool
    {
        $c = $w[0]; $args = array_slice($w, 1);
        if ($c === 'git') {
            $sub = self::gitSub($args);
            if (!in_array($sub, self::GIT_READ, true)) return $sub === 'stash' && in_array('list', $args, true) || $sub === 'config' && array_intersect($args, ['--get', '--list', '-l']);
            if ($sub === 'branch' && preg_grep('/^-(d|D|m|M|c|C|-delete|-move|-copy|-set-upstream-to|u)$/', $args)) return false;
            if ($sub === 'remote' && array_diff($args, ['remote', '-v', '--verbose', 'show', 'get-url', 'origin'])) return false;
            return true;
        }
        if (!in_array($c, self::READ, true)) return false;
        if ($c === 'sed' && preg_grep('/^(-i|--in-place)/', $args)) return false;
        if ($c === 'sed' && preg_grep('/(^|;)\s*w\s/', $args)) return false;
        if (in_array($c, ['sort'], true) && preg_grep('/^-o/', $args)) return false;
        if (in_array($c, ['env', 'command', 'time'], true) && $args && !preg_match('/^-/', $args[0])) return false; // env X runs X
        return true;
    }

    /**
     * What the board learns your answers by (1.55.0): one key per part of the command that does not only read (its
     * first two words: "npx vitest", "git commit", "php tests/x.php"), "Edit <folder>" for a file, "WebFetch <host>"
     * for a page. [] for a request that only reads; null when it cannot be learned (risky, or code it cannot read).
     */
    public static function keys(string $tool, array $in): ?array
    {
        $a = self::assess($tool, $in);
        if ($a['level'] === 'risk') return null;
        if ($a['level'] === 'read') return [];
        if ($tool === 'Bash') {
            $keys = [];
            foreach (self::split((string)($in['command'] ?? '')) ?? [] as [$seg, $writes]) {
                $w = self::words($seg);
                if (!$w || (!$writes && self::readOnly($w))) continue;
                $keys[] = mb_substr(implode(' ', array_slice($w, 0, 2)) . ($writes ? ' >' : ''), 0, 200);
            }
            return array_values(array_unique($keys)) ?: null;
        }
        if (in_array($tool, ['Edit', 'Write', 'MultiEdit', 'NotebookEdit'], true)) {
            $p = (string)($in['file_path'] ?? $in['notebook_path'] ?? '');
            return $p === '' ? null : ['Edit ' . mb_substr(dirname($p), 0, 200)];
        }
        if ($tool === 'WebFetch') { $h = parse_url((string)($in['url'] ?? ''), PHP_URL_HOST); return $h ? ['WebFetch ' . strtolower($h)] : null; }
        return str_starts_with($tool, 'mcp__') || $tool === '' ? null : [$tool];
    }

    /**
     * Claude Code's "always allow" suggestions narrow enough to save: addRules / allow entries only, on the tools above,
     * never a rule on a whole tool, a lone interpreter or a risky command. Saved in the project's .claude/settings.local.json
     * (yours, not committed). Returns the entries to send back, with their rules as Claude Code writes them.
     */
    public static function rules(array $suggestions): array
    {
        $out = [];
        foreach ($suggestions as $s) {
            if (!is_array($s) || ($s['type'] ?? '') !== 'addRules' || ($s['behavior'] ?? '') !== 'allow' || !is_array($s['rules'] ?? null)) continue;
            $ok = [];
            foreach ($s['rules'] as $r) if (is_array($r) && self::ruleOk($r)) $ok[] = ['toolName' => (string)$r['toolName'], 'ruleContent' => (string)$r['ruleContent']];
            if ($ok && count($ok) === count($s['rules'])) foreach ($ok as $r) $out[self::show($r)] = $r;
        }
        return array_values(array_slice($out, 0, 5));
    }

    public static function show(array $r): string { return $r['toolName'] . '(' . $r['ruleContent'] . ')'; }

    private static function ruleOk(array $r): bool
    {
        $tool = (string)($r['toolName'] ?? ''); $c = trim((string)($r['ruleContent'] ?? ''));
        if (!in_array($tool, self::RULE_TOOLS, true) || $c === '' || mb_strlen($c) > 300) return false;
        if ($tool !== 'Bash') {
            if (in_array($tool, ['Edit', 'Write', 'MultiEdit', 'NotebookEdit'], true) && (str_contains($c, '.claude/settings') || str_contains($c, '.claude/**') || str_contains($c, '.claude/*'))) return false;
            return !in_array(rtrim($c, '/*'), ['', '~', '/', '.', '..'], true) && $c !== '//**';
        }
        $base = trim(preg_replace('/(:\*|\s*\*)$/', '', $c));
        if ($base === '' || str_contains($base, '*') && preg_match('/^\S+\s*\*/', $base)) return false;
        $w = self::words($base);
        if (!$w) return false;
        if (count($w) === 1 && in_array(basename($w[0]), self::BROAD, true)) return false;
        if (count($w) === 2 && in_array($w[0] . ' ' . $w[1], self::BROAD2, true)) return false;
        return self::bash($base)['level'] !== 'risk';
    }
}
