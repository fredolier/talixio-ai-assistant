<?php
declare(strict_types=1);
// Migration: the settings of an installation made with install.sh (/etc/talixio/talixio.env) into
// the instance's own settings (data/settings.json). Server-level lines are left out.
//   php import-env.php /path/to/talixio.env
require __DIR__ . '/../../src/autoload.php';

use Talixio\Settings;

$skip = ['TALIXIO_DATA_DIR', 'TALIXIO_WEB_BIND', 'TALIXIO_CLAUDE_BIN', 'TALIXIO_SIGNAL_CLI', 'TALIXIO_OLLAMA_URL', 'TALIXIO_EMBEDDINGS', 'TALIXIO_EMBEDDINGS_MODEL', 'TALIXIO_CHROME_BIN', 'TALIXIO_BACKUP_DIR'];
$dir = getenv('TALIXIO_DATA_DIR') ?: '/data';
$fields = Settings::fields();
$done = 0; $left = [];
foreach (file($argv[1] ?? '/old.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $m) || in_array($m[1], $skip, true)) continue;
    $v = trim($m[2], " \"'");
    if ($v === '') continue;
    if ($m[1] === 'TALIXIO_WEB_PASSWORD') {
        if ((Settings::load($dir)['TALIXIO_WEB_PASSWORD_HASH'] ?? '') === '') { Settings::setPassword($dir, $v); $done++; }
        continue;
    }
    if (!isset($fields[$m[1]])) { $left[] = $m[1]; continue; }
    if (array_key_exists($m[1], Settings::load($dir))) continue; // a value set in the app wins
    if (!Settings::save($dir, [$m[1] => $v])) $done++; else $left[] = $m[1];
}
echo "imported $done setting(s)" . ($left ? '; not imported: ' . implode(', ', $left) : '') . "\n";
