<?php
declare(strict_types=1);
namespace Talixio;

/**
 * People: built from your email (every sender you hear from) and kept up to date by the assistant
 * (contact_save) when it learns something about someone. A contact's page gathers what is known:
 * notes, recent emails, documents and conversations that mention them.
 */
final class Contacts
{
    public function __construct(private App $app)
    {
        $app->store->db->exec("CREATE TABLE IF NOT EXISTS contacts(id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '',
            org TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '', last_ts INTEGER NOT NULL DEFAULT 0, last_what TEXT NOT NULL DEFAULT '', count INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL, updated INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS contacts_email ON contacts(email);");
    }

    public function get(int $id): ?array { return $this->app->store->q('SELECT * FROM contacts WHERE id = ?', [$id])->fetch() ?: null; }

    private function byEmail(string $email): ?array
    {
        return $email === '' ? null : ($this->app->store->q('SELECT * FROM contacts WHERE email = ?', [strtolower($email)])->fetch() ?: null);
    }

    /** Someone was heard from (an email...): create or refresh the contact. */
    public function seen(string $name, string $email, int $ts, string $what): void
    {
        $email = strtolower(trim($email));
        if ($email === '' || preg_match('/^(no-?reply|do-?not-?reply|notifications?|mailer-daemon|bounce)/i', $email)) return;
        $st = $this->app->store;
        if ($c = $this->byEmail($email)) {
            $better = ($c['name'] === '' || $c['name'] === $c['email']) && $name !== '' && $name !== $email;
            $st->q('UPDATE contacts SET name = ?, count = count + 1, last_ts = MAX(last_ts, ?), last_what = CASE WHEN ? >= last_ts THEN ? ELSE last_what END, updated = ? WHERE id = ?',
                [$better ? $name : $c['name'], $ts, $ts, mb_substr($what, 0, 200), time(), $c['id']]);
        } else {
            $st->q('INSERT INTO contacts(name, email, last_ts, last_what, count, created, updated) VALUES(?,?,?,?,1,?,?)', [$name !== '' ? $name : $email, $email, $ts, mb_substr($what, 0, 200), time(), time()]);
        }
    }

    /** Create or update (by id, else by email, else by exact name). A note is added with its date. */
    public function save(array $d): array
    {
        $st = $this->app->store;
        $email = strtolower(trim((string)($d['email'] ?? '')));
        $c = isset($d['id']) ? $this->get((int)$d['id']) : ($this->byEmail($email)
            ?? (!empty($d['name']) ? ($st->q('SELECT * FROM contacts WHERE name = ? COLLATE NOCASE', [trim($d['name'])])->fetch() ?: null) : null));
        $fields = [];
        foreach (['name', 'phone', 'org'] as $k) if (isset($d[$k]) && trim((string)$d[$k]) !== '') $fields[$k] = mb_substr(trim((string)$d[$k]), 0, 200);
        if ($email !== '') $fields['email'] = $email;
        if (array_key_exists('notes', $d)) $fields['notes'] = mb_substr((string)$d['notes'], 0, 20000); // the whole notes (edited in the app)
        if (!empty($d['note'])) $fields['notes'] = trim(($c['notes'] ?? '') . "\n- " . date('Y-m-d') . ': ' . trim((string)$d['note'])); // one more fact
        if (!$c) {
            if (empty($fields['name'])) throw new \InvalidArgumentException('a name is needed');
            $fields += ['created' => time(), 'updated' => time()];
            $st->q('INSERT INTO contacts(' . implode(',', array_keys($fields)) . ') VALUES(' . implode(',', array_fill(0, count($fields), '?')) . ')', array_values($fields));
            return $this->get((int)$st->db->lastInsertId());
        }
        if ($fields) $st->q('UPDATE contacts SET ' . implode(',', array_map(fn($k) => "$k = ?", array_keys($fields))) . ', updated = ? WHERE id = ?', [...array_values($fields), time(), $c['id']]);
        return $this->get((int)$c['id']);
    }

    public function delete(int $id): void { $this->app->store->q('DELETE FROM contacts WHERE id = ?', [$id]); }

    public function search(string $q = '', int $limit = 100): array
    {
        $q = trim($q);
        if ($q === '') return $this->app->store->q('SELECT * FROM contacts ORDER BY last_ts DESC, name COLLATE NOCASE LIMIT ?', [$limit])->fetchAll();
        $like = '%' . addcslashes($q, '%_\\') . '%';
        return $this->app->store->q("SELECT * FROM contacts WHERE name LIKE ? ESCAPE '\\' OR email LIKE ? ESCAPE '\\' OR org LIKE ? ESCAPE '\\' OR notes LIKE ? ESCAPE '\\'
            ORDER BY last_ts DESC LIMIT ?", [$like, $like, $like, $like, $limit])->fetchAll();
    }

    /** Everything known about one person. */
    public function profile(int $id): array
    {
        $c = $this->get($id) ?? throw new \InvalidArgumentException('no such contact');
        $emails = $c['email'] !== '' ? $this->app->store->q('SELECT uid, ts, subject, label, status, summary FROM mail_triage WHERE email = ? ORDER BY ts DESC LIMIT 15', [$c['email']])->fetchAll() : [];
        $docs = []; $talks = [];
        try {
            $docs = $this->app->library()->search($c['name'], '/', 6);
            $talks = array_map(fn($h) => ['ts' => (int)$h['ts'], 'label' => $h['label'], 'text' => preg_replace('/\s+/', ' ', mb_substr($h['text'], 0, 240))],
                array_filter($this->app->recall()->search($c['name'], 6, PHP_INT_MAX, ['message', 'summary']), fn($h) => $h['keyword']));
        } catch (\Throwable $e) { Log::warn("contact profile: {$e->getMessage()}"); }
        return ['contact' => $c, 'emails' => $emails, 'documents' => $docs, 'conversations' => array_values($talks)];
    }

    public function format(array $rows): string
    {
        if (!$rows) return 'no contacts found';
        return implode("\n", array_map(fn($c) => "#{$c['id']} {$c['name']}" . ($c['email'] ? " <{$c['email']}>" : '') . ($c['phone'] ? ", {$c['phone']}" : '') . ($c['org'] ? ", {$c['org']}" : '')
            . ($c['last_ts'] ? ', last contact ' . date('Y-m-d', (int)$c['last_ts']) . ($c['last_what'] ? " ({$c['last_what']})" : '') : '')
            . ($c['notes'] !== '' ? "\n  " . str_replace("\n", "\n  ", mb_substr(trim($c['notes']), 0, 1500)) : ''), $rows));
    }
}
