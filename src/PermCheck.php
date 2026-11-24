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

    /** Interpreters: a script is learned by itself, then by its folder (1.56.0). */
    private const INTERP = ['php', 'python', 'python3', 'node', 'ruby', 'perl', 'deno', 'bun', 'tsx', 'ts-node'];
    /** Package managers: "npm run <script>" is learned by itself, then as "npm run". */
    private const PKG = ['npm', 'pnpm', 'yarn', 'bun'];
    /** Commands that change files: one family, while every path they name stays in the project. */
    private const FILE_OPS = ['mv', 'cp', 'mkdir', 'touch', 'ln', 'sed', 'tee', 'patch'];
    /** Never learned beyond their first two words, besides BROAD: they reach outside the paths they name. */
    private const NARROW = ['tar', 'unzip', 'zip', 'gzip', 'gunzip', 'install'];

    /**
     * What the board learns your answers by: one ladder per part of the command that does not only read, from the
     * narrowest key to the widest (1.56.0): ["./tools-workflow check-gate", "./tools-workflow"], ["php tests/lib/a.php",
     * "php tests/lib/*"], ["npm run build", "npm run"], ["mv (in project)", "file changes (in project)"], ["Edit src/a",
     * "Edit src/**", "Edit **"]. Without the project's folder, or outside it, a part is learned by its first two words
     * ("npx vitest", "git commit") and a file edit by its folder, as in 1.55.0. git, npx and the other BROAD programs never
     * widen. [] for a request that only reads; null when it cannot be learned (risky, or code it cannot read).
     */
    public static function keys(string $tool, array $in, string $cwd = ''): ?array
    {
        $a = self::assess($tool, $in);
        if ($a['level'] === 'risk') return null;
        if ($a['level'] === 'read') return [];
        $cwd = rtrim($cwd, '/');
        if ($cwd !== '' && ($cwd[0] !== '/' || self::resolve('/', $cwd) !== $cwd)) $cwd = '';
        if ($tool === 'Bash') {
            $out = []; $base = $cwd;
            foreach (self::split((string)($in['command'] ?? '')) ?? [] as [$seg, $writes]) {
                $w = self::words($seg);
                if (!$w) continue;
                if ($w[0] === 'cd') { $base = $base !== '' && isset($w[1]) && $w[1][0] !== '-' ? (self::resolve($base, $w[1]) ?? '') : ''; continue; }
                if (!$writes && self::readOnly($w)) continue;
                $l = array_map(fn($k) => mb_substr($k, 0, 200) . ($writes ? ' >' : ''), self::ladder($w, $base === '' ? '' : $cwd, $base));
                $out[implode("\n", $l)] = $l;
            }
            return array_values($out) ?: null;
        }
        if (in_array($tool, ['Edit', 'Write', 'MultiEdit', 'NotebookEdit'], true)) {
            $p = (string)($in['file_path'] ?? $in['notebook_path'] ?? '');
            if ($p === '') return null;
            $rel = $cwd === '' ? null : self::inside($cwd, $cwd, dirname($p));
            if ($rel === null) return [['Edit ' . mb_substr(dirname($p), 0, 200)]];
            if ($rel === '.') return [['Edit .', 'Edit **']];
            $top = explode('/', $rel)[0];
            if (in_array($top, ['.claude', '.git'], true)) return [['Edit ' . mb_substr($rel, 0, 200)]];
            return [array_values(array_unique(['Edit ' . mb_substr($rel, 0, 200), 'Edit ' . mb_substr($top, 0, 200) . '/**', 'Edit **']))];
        }
        if ($tool === 'WebFetch') { $h = parse_url((string)($in['url'] ?? ''), PHP_URL_HOST); return $h ? [['WebFetch ' . strtolower($h)]] : null; }
        return str_starts_with($tool, 'mcp__') || $tool === '' ? null : [[$tool]];
    }

    /** The ladder of one part (see keys); $cwd '' when the folder it runs in is not known. */
    private static function ladder(array $w, string $cwd, string $base): array
    {
        $two = function () use (&$w) { return implode(' ', array_slice($w, 0, 2)); };
        if ($cwd === '') return [$two()];
        if (str_contains($w[0], '/')) {
            $p = self::inside($cwd, $base, $w[0]);
            if ($p === null || $p === '.') return [$two()];
            $w[0] = './' . $p;
        }
        $c = $w[0]; $a1 = $w[1] ?? '';
        if (in_array($c, self::PKG, true) && $a1 === 'run' && isset($w[2])) return ["$c run {$w[2]}", "$c run"];
        if (in_array($c, self::INTERP, true)) {
            if ($a1 === '' || $a1[0] === '-') return [$two()];
            $p = self::inside($cwd, $base, $a1);
            if ($p === null || $p === '.') return [$two()];
            $d = dirname($p);
            return ["$c $p", "$c " . ($d === '.' ? '' : "$d/") . '*'];
        }
        if (in_array($c, self::FILE_OPS, true)) {
            $paths = []; $script = $c !== 'sed'; $skip = false; $name = $c;
            foreach (array_slice($w, 1) as $x) {
                if ($skip) { $skip = false; continue; }
                if ($x !== '' && $x[0] === '-') {
                    if ($c === 'sed' && preg_match('/^(-i|--in-place)/', $x)) $name = 'sed -i';
                    if ($c === 'sed' && in_array($x, ['-e', '-f', '--expression', '--file'], true)) { $script = true; $skip = true; }
                    continue;
                }
                if (!$script) { $script = true; continue; }
                $paths[] = $x;
            }
            foreach ($paths as $x) if (self::inside($cwd, $base, $x) === null) return [$two()];
            return $paths ? ["$name (in project)", 'file changes (in project)'] : [$two()];
        }
        if (in_array($c, self::BROAD, true) || in_array($c, self::NARROW, true) || in_array($c, self::PKG, true)) return [$two()];
        return array_values(array_unique([$two(), $c]));
    }

    /** $p (absolute, or relative to $base) as a path relative to the project $cwd: '.' for the project itself; null outside it. */
    private static function inside(string $cwd, string $base, string $p): ?string
    {
        $abs = self::resolve($base, $p);
        if ($abs === null) return null;
        if ($abs === $cwd) return '.';
        return str_starts_with($abs, $cwd . '/') ? substr($abs, strlen($cwd) + 1) : null;
    }

    /** $p made absolute against $base, with . and .. resolved; null when the shell would expand it ($VAR, ~). */
    private static function resolve(string $base, string $p): ?string
    {
        if ($p === '' || $p[0] === '~' || str_contains($p, '$') || str_contains($p, '`')) return null;
        $parts = [];
        foreach (explode('/', $p[0] === '/' ? $p : "$base/$p") as $x) {
            if ($x === '' || $x === '.') continue;
            if ($x === '..') array_pop($parts); else $parts[] = $x;
        }
        return '/' . implode('/', $parts);
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
