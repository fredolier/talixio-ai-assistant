<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Python scripts the assistant writes for itself, to redo a mechanical task the same way every time (convert a bank
 * export, tidy a CSV, compute a quote...) instead of rewriting the code. They live in workspace/scripts/<name>.py with
 * a docstring header, and are mounted read-only at /tools in the sandbox:
 *   """
 *   name: csv-to-ledger
 *   description: Convert a bank CSV export into the ledger format
 *   usage: csv-to-ledger <in.csv> <out.csv>
 *   """
 * Only names, descriptions and usage go into every prompt. With TALIXIO_SHELL=ask the user approves a script's code
 * once (when it is saved or changed); running that same code again needs no approval.
 */
final class Scripts
{
    public function __construct(private App $app) {}

    public function dir(): string
    {
        $d = $this->app->cfg->workspaceDir() . '/scripts';
        if (!is_dir($d)) mkdir($d, 0750, true);
        return $d;
    }

    private function file(string $name): string { return $this->dir() . '/' . Skills::slug($name) . '.py'; }

    /** @return array<array{name:string,description:string,usage:string,updated:int,used:int,approved:bool}> */
    public function all(): array
    {
        $out = [];
        foreach (glob($this->dir() . '/*.py') ?: [] as $f) {
            $name = basename($f, '.py');
            [$meta] = self::parse((string)file_get_contents($f));
            $out[] = ['name' => $name, 'description' => $meta['description'] ?? '', 'usage' => $meta['usage'] ?? '', 'updated' => filemtime($f),
                'used' => (int)$this->app->store->get("script_used_$name", '0'), 'approved' => $this->approved($name)];
        }
        usort($out, fn($a, $b) => $b['used'] <=> $a['used'] ?: strcmp($a['name'], $b['name']));
        return $out;
    }

    /** One line per script for the system prompt. */
    public function index(int $max = 40): string
    {
        return implode("\n", array_map(fn($s) => "- {$s['name']}: " . mb_substr($s['description'], 0, 160) . ($s['usage'] !== '' ? " (usage: " . mb_substr($s['usage'], 0, 120) . ')' : ''),
            array_slice($this->all(), 0, $max)));
    }

    /** [header fields, code without the header]. */
    public static function parse(string $text): array
    {
        $text = str_replace("\r\n", "\n", $text);
        $meta = [];
        if (preg_match('/^(?:#![^\n]*\n)?\s*"""\n?(.*?)"""\n?/s', $text, $m) && preg_match('/^\s*name\s*:/m', $m[1])) {
            foreach (explode("\n", $m[1]) as $l) if (preg_match('/^\s*(name|description|usage)\s*:\s*(.*)$/', $l, $f)) $meta[$f[1]] = trim($f[2]);
            $text = substr($text, strlen($m[0]));
        }
        return [$meta, trim($text, "\n")];
    }

    public function read(string $name): ?string
    {
        $f = $this->file($name);
        return is_file($f) ? (string)file_get_contents($f) : null;
    }

    /** The file as it would be saved, to show for approval before writing it. */
    public static function render(string $slug, string $description, string $usage, string $code): string
    {
        $one = fn(string $s) => trim(str_replace('"""', "'''", preg_replace('/\s+/', ' ', $s)));
        [, $code] = self::parse($code); // drop any header the model wrote itself
        return "\"\"\"\nname: $slug\ndescription: {$one($description)}\n" . ($one($usage) !== '' ? "usage: {$one($usage)}\n" : '') . "\"\"\"\n" . trim($code, "\n") . "\n";
    }

    /** @return string the saved file's content */
    public function save(string $name, string $description, string $code, string $usage = '', bool $approved = false): string
    {
        $slug = Skills::slug($name);
        if (trim($description) === '') throw new \InvalidArgumentException('a script needs a description saying what it does');
        if (trim(self::parse($code)[1]) === '') throw new \InvalidArgumentException('empty script');
        $content = self::render($slug, $description, $usage, $code);
        file_put_contents($this->file($slug), $content, LOCK_EX);
        if ($approved) $this->approve($slug);
        return $content;
    }

    /** Saving from the editor: the user wrote or read it, so it is approved. */
    public function writeRaw(string $name, string $content): void
    {
        [$meta, $code] = self::parse($content);
        $this->save($meta['name'] ?? $name, $meta['description'] ?? self::guessDescription($code), $code, $meta['usage'] ?? '', true);
        if (isset($meta['name']) && Skills::slug($meta['name']) !== Skills::slug($name)) $this->delete($name); // renamed
    }

    /**
     * A script the user brings (written in the editor, uploaded, or a .py of the library): approved, named by its header
     * or else by $name. $replace: false refuses to overwrite a script of the same name.
     * @return string its name
     */
    public function import(string $content, string $name, bool $replace = false): string
    {
        [$meta] = self::parse($content);
        $name = trim($meta['name'] ?? $name);
        if (!preg_match('/[a-z0-9]/i', $name)) throw new \InvalidArgumentException('give the script a name (a "name:" line in its header)');
        $slug = Skills::slug($name);
        if (!$replace && $this->read($slug) !== null) throw new \InvalidArgumentException("a script named $slug already exists: open it to change it");
        $this->writeRaw($slug, $content);
        return $slug;
    }

    /** Without a header: the module docstring's or the first comment's first line. */
    private static function guessDescription(string $code): string
    {
        if (preg_match('/^(?:#![^\n]*\n)?\s*(?:"""|\'\'\')\s*([^\n]+)/', $code, $m)) return trim($m[1], " \t\"'");
        if (preg_match('/^\s*#(?!!)\s*(\S[^\n]*)/m', $code, $m)) return trim($m[1]);
        return 'Script';
    }

    public function approve(string $name): void
    {
        $c = $this->read($name);
        if ($c !== null) $this->app->store->set('script_ok_' . Skills::slug($name), hash('sha256', $c));
    }

    /** Has the user approved this exact code (it may have been changed since, on disk or by a newer save)? */
    public function approved(string $name): bool
    {
        $c = $this->read($name);
        return $c !== null && hash_equals($this->app->store->get('script_ok_' . Skills::slug($name)), hash('sha256', $c));
    }

    public function delete(string $name): bool
    {
        $f = $this->file($name);
        $this->app->store->set('script_ok_' . Skills::slug($name), '');
        return is_file($f) && unlink($f);
    }

    /** The shell command that runs a script with its arguments. */
    public function command(string $name, array $args): string
    {
        return 'python3 /tools/' . Skills::slug($name) . '.py' . implode('', array_map(fn($a) => ' ' . escapeshellarg((string)$a), $args));
    }

    public function used(string $name): void
    {
        $k = 'script_used_' . Skills::slug($name);
        $this->app->store->set($k, (string)((int)$this->app->store->get($k, '0') + 1));
    }
}
