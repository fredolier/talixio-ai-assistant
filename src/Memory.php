<?php
declare(strict_types=1);
namespace Talixio;

final class Memory
{
    private \PDO $db;

    public function __construct(string $path)
    {
        if ($path !== ':memory:' && !is_dir(dirname($path))) mkdir(dirname($path), 0750, true);
        $this->db = new \PDO("sqlite:$path", null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE IF NOT EXISTS messages(id INTEGER PRIMARY KEY, chat TEXT, role TEXT, content TEXT, ts REAL);
                         CREATE TABLE IF NOT EXISTS facts(id INTEGER PRIMARY KEY, chat TEXT, text TEXT, ts REAL)');
    }

    public function addMessage(string $chat, string $role, string $content): void
    {
        $this->db->prepare('INSERT INTO messages(chat,role,content,ts) VALUES(?,?,?,?)')
            ->execute([$chat, $role, $content, microtime(true)]);
    }

    /** @return array<array{role:string,content:string}> */
    public function history(string $chat, int $turns): array
    {
        $st = $this->db->prepare('SELECT role, content FROM messages WHERE chat=? ORDER BY id DESC LIMIT ?');
        $st->execute([$chat, $turns * 2]);
        $rows = array_reverse($st->fetchAll(\PDO::FETCH_ASSOC));
        while ($rows && $rows[0]['role'] !== 'user') array_shift($rows); // providers need a leading user turn
        return $rows;
    }

    public function remember(string $chat, string $text): void
    {
        $this->db->prepare('INSERT INTO facts(chat,text,ts) VALUES(?,?,?)')->execute([$chat, $text, microtime(true)]);
    }

    /** @return string[] */
    public function facts(string $chat): array
    {
        $st = $this->db->prepare('SELECT text FROM facts WHERE chat=? ORDER BY id');
        $st->execute([$chat]);
        return $st->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function forget(string $chat, string $needle): int
    {
        $st = $this->db->prepare('DELETE FROM facts WHERE chat=? AND instr(lower(text), lower(?)) > 0');
        $st->execute([$chat, $needle]);
        return $st->rowCount();
    }
}
