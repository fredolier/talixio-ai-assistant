<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Settings editable in the web app. Values saved there go to data/settings.json and override
 * /etc/talixio/talixio.env (which keeps the defaults). They are applied to the process environment,
 * so child processes (jobs, Claude Code, signal-cli...) see them too.
 */
final class Settings
{
    /** group => [title, intro, fields: [key, label, type, help, options]] ; types: text number secret bool select multi list url */
    public static function schema(): array
    {
        return [
            'assistant' => ['Assistant', '', [
                ['TALIXIO_NAME', 'Name', 'text', 'Your assistant\'s name.'],
                ['TALIXIO_LANG', 'Interface language', 'select', 'Automatic: the language of your browser. The assistant always answers in the language you write in.', ['auto' => 'Automatic', 'en' => 'English', 'fr' => 'Français']],
                ['TALIXIO_TIMEZONE', 'Time zone', 'text', 'e.g. Europe/Paris. Used for reminders and the calendar.'],
                ['TALIXIO_PUBLIC_URL', 'Public address', 'url', 'For links you share with people outside, e.g. https://assistant.example.com/fred. Empty: the address you open the app with.'],
                ['TALIXIO_HISTORY_MESSAGES', 'Recent messages kept word for word (max)', 'number', 'Older ones are compacted into summaries (weeks and months get shorter over time) and stay searchable.'],
                ['TALIXIO_HISTORY_TOKENS', 'Size of the recent messages (tokens)', 'number', 'Long messages (pasted documents…) are shortened or left to memory search beyond this. Fewer tokens = cheaper answers.'],
            ]],
            'brain' => ['AI model', 'The assistant\'s brain. "Check configuration" makes one test call per tier.', [
                ['TALIXIO_PROVIDER', 'Provider', 'select', 'claude-code uses your Claude Pro/Max plan (installed with --with-claude-code).', ['anthropic' => 'Claude (API)', 'claude-code' => 'Claude Code (Pro/Max plan)', 'gemini' => 'Gemini (API)', 'openai' => 'OpenAI or compatible server (Ollama…)', 'echo' => 'Echo (test, no model)']],
                ['ANTHROPIC_API_KEY', 'Claude API key', 'secret', 'console.anthropic.com'],
                ['CLAUDE_CODE_OAUTH_TOKEN', 'Claude Code token', 'secret', 'From "claude setup-token" (see the README).'],
                ['GEMINI_API_KEY', 'Gemini API key', 'secret', 'aistudio.google.com/apikey. Also used for transcription, memory search and web search.'],
                ['TALIXIO_GEMINI_PAID', 'My Gemini key is on a paid plan', 'bool', 'With billing set up in Google AI Studio, what you send is not used to train Google\'s models. The free tier may be: the Privacy page counts it as personal use.'],
                ['OPENAI_API_KEY', 'OpenAI API key', 'secret', ''],
                ['OPENAI_BASE_URL', 'OpenAI-compatible server address', 'url', 'For Ollama, vLLM, OpenRouter… Empty = OpenAI.'],
                ['TALIXIO_MODEL_FAST', 'Fast model', 'text', 'Empty = chosen automatically. Background work, summaries, simple lookups.'],
                ['TALIXIO_MODEL', 'Standard model', 'text', 'Empty = chosen automatically. Conversation.'],
                ['TALIXIO_MODEL_DEEP', 'Deep model', 'text', 'Empty = chosen automatically. Messages starting with /deep and hard reasoning.'],
                ['TALIXIO_DEEP_THINKING_TOKENS', 'Claude extended thinking (tokens)', 'number', 'Deep tier only. 0 = off.'],
                ['TALIXIO_DEEP_REASONING_EFFORT', 'Reasoning effort (deep tier)', 'select', 'How hard the deep model thinks. Claude: adaptive thinking at this level (the thinking tokens above apply to Claude 4.5 and older only). OpenAI/Gemini get low/medium/high.', self::EFFORT_CHOICES],
            ]],
            'budget' => ['Budget', 'Estimated from list prices. 0 = no limit. At 80%: fast model and no sub-agents; at 100%: no more model calls.', [
                ['TALIXIO_BUDGET_DAILY_USD', 'Daily budget ($)', 'number', 'Set 0 with the Claude Code plan (already paid for).'],
                ['TALIXIO_BUDGET_MONTHLY_USD', 'Monthly budget ($)', 'number', ''],
                ['TALIXIO_MAX_PARALLEL_JOBS', 'Sub-agents in parallel', 'number', ''],
                ['TALIXIO_JOB_MAX_USD', 'Cap per background job ($)', 'number', 'Default for each job. A job that reaches it is stopped (it is warned at 80% to write its report). 0 = no cap.'],
                ['TALIXIO_JOB_MAX_USD_LIMIT', 'Highest cap for one job ($)', 'number', 'When you ask for a bigger job ("budget 5 $"), the assistant may raise its cap up to this.'],
                ['TALIXIO_JOB_EFFORT', 'Effort of background jobs', 'select', 'Default; can be chosen per job. Higher: deeper thinking and more steps (low 15, medium 30, high 60, xhigh 100, max 200), so longer and more expensive.', ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'very high', 'max' => 'max']],
                ['TALIXIO_PRICES', 'Custom prices', 'text', 'pattern=input/output per million tokens, e.g. sonnet=3/15,gemini-3.8-flash=0.75/3.75'],
            ]],
            'notify' => ['Notifications', 'Where the assistant reaches you (reminders, briefings, job results, approvals).', [
                ['TALIXIO_NOTIFY', 'Channels used', 'multi', 'Only configured channels are used.', ['webpush' => 'App notifications', 'email' => 'Email', 'signal' => 'Signal', 'telegram' => 'Telegram', 'ntfy' => 'ntfy']],
                ['TALIXIO_NOTIFY_NEUTRAL', 'Notifications without content', 'bool', 'Only "New message in General": the content stays in the app. Always so for Confidential and Secret spaces.'],
                ['TALIXIO_HEARTBEAT_MINUTES', 'Periodic check (minutes)', 'number', 'Reads the HEARTBEAT.md checklist and tells you if something needs attention. 0 = off. Each check is a model call.'],
                ['TALIXIO_NTFY_URL', 'ntfy address', 'secret', 'https://ntfy.sh/<long-random-topic>'],
                ['TALIXIO_NTFY_TOKEN', 'ntfy token', 'secret', 'Only for a protected ntfy server.'],
                ['TALIXIO_VAPID_SUBJECT', 'Contact for app notifications', 'text', 'mailto:you@example.com'],
            ]],
            'email' => ['Assistant\'s email', 'The assistant\'s own mailbox (e.g. assistant@talixio.com): notifications by email, and chat by email when IMAP is filled in. OVH MX Plan: ssl0.ovh.net, SMTP 465, IMAP 993.', [
                ['TALIXIO_SMTP_HOST', 'SMTP server', 'text', ''],
                ['TALIXIO_SMTP_PORT', 'SMTP port', 'number', '465 (TLS) or 587 (STARTTLS)'],
                ['TALIXIO_SMTP_SECURITY', 'SMTP security', 'select', '', ['auto' => 'auto', 'tls' => 'TLS', 'starttls' => 'STARTTLS', 'none' => 'none (local relay)']],
                ['TALIXIO_SMTP_USER', 'Username', 'text', ''],
                ['TALIXIO_SMTP_PASSWORD', 'Password', 'secret', ''],
                ['TALIXIO_EMAIL_FROM', 'Sender', 'text', 'Talixio <assistant@talixio.com>'],
                ['TALIXIO_EMAIL_TO', 'Your address (notifications)', 'text', ''],
                ['TALIXIO_IMAP_HOST', 'IMAP server (chat by email)', 'text', 'Empty = notifications only.'],
                ['TALIXIO_IMAP_PORT', 'IMAP port', 'number', ''],
                ['TALIXIO_IMAP_USER', 'IMAP username', 'text', 'Empty = SMTP username.'],
                ['TALIXIO_IMAP_PASSWORD', 'IMAP password', 'secret', 'Empty = SMTP password.'],
                ['TALIXIO_EMAIL_ALLOWED', 'Allowed senders', 'list', 'The only addresses it answers. Empty = your address.'],
                ['TALIXIO_EMAIL_REQUIRE_AUTH', 'Require a verified sender (SPF/DKIM)', 'bool', 'Ignores email with a forged sender. Untick only if your emails get no answer.'],
            ]],
            'mailbox' => ['Your mailbox', 'Your own mailbox (e.g. frederic@talixio.com): reads without marking as read, writes drafts, sends only after your approval.', [
                ['TALIXIO_MAILBOX_IMAP_HOST', 'IMAP server', 'text', 'OVH: ssl0.ovh.net. Empty = off.'],
                ['TALIXIO_MAILBOX_IMAP_PORT', 'IMAP port', 'number', ''],
                ['TALIXIO_MAILBOX_SMTP_HOST', 'SMTP server', 'text', 'Empty = same server as IMAP.'],
                ['TALIXIO_MAILBOX_SMTP_PORT', 'SMTP port', 'number', ''],
                ['TALIXIO_MAILBOX_USER', 'Username', 'text', ''],
                ['TALIXIO_MAILBOX_PASSWORD', 'Password', 'secret', ''],
                ['TALIXIO_MAILBOX_FROM', 'Your name and address', 'text', 'Frédéric <frederic@talixio.com>'],
                ['TALIXIO_MAIL_TRIAGE', 'Sort new emails', 'bool', 'Every 15 minutes: to answer / to do / to read / low priority (Mail page, morning briefing). Learns from your corrections; the fast model only when unsure.'],
            ]],
            'calendar' => ['Calendar', 'Google Calendar: fill in your OAuth client below, then press "Connect Google Calendar" (Settings → Google Calendar card; the steps are there). Other calendars: iCal links to read, CalDAV to add events. Events are always added after your approval.', [
                ['TALIXIO_GOOGLE_CLIENT_ID', 'Google OAuth client ID', 'text', 'From console.cloud.google.com → APIs & Services → Credentials (type "Web application").'],
                ['TALIXIO_GOOGLE_CLIENT_SECRET', 'Google OAuth client secret', 'secret', ''],
                ['TALIXIO_CALENDAR_ICS', 'iCal links', 'secret', 'Several links separated by commas. These links give access to your calendar: keep them secret.'],
                ['TALIXIO_CALDAV_URL', 'CalDAV calendar address', 'url', 'Nextcloud, Fastmail, iCloud…'],
                ['TALIXIO_CALDAV_USER', 'CalDAV username', 'text', ''],
                ['TALIXIO_CALDAV_PASSWORD', 'CalDAV password', 'secret', ''],
            ]],
            'spotify' => ['Spotify', 'Play music on your phone or speakers from the chat (Spotify Premium needed). Create your Spotify app, then press "Connect Spotify" (Settings → Spotify card; the steps are there).', [
                ['TALIXIO_SPOTIFY_CLIENT_ID', 'Spotify client ID', 'text', 'From developer.spotify.com → Dashboard → your app → Settings.'],
                ['TALIXIO_SPOTIFY_CLIENT_SECRET', 'Spotify client secret', 'secret', ''],
                ['TALIXIO_SPOTIFY_DEVICE', 'Usual device', 'text', 'Where to play when nothing is playing, e.g. "Galaxy S25 Ultra". Empty = your phone.'],
            ]],
            'signal' => ['Signal', 'A number dedicated to the assistant, registered with "sudo talixio signal register" (see the README).', [
                ['TALIXIO_SIGNAL_NUMBER', 'Assistant\'s number', 'text', '+33…'],
                ['TALIXIO_SIGNAL_ALLOWED', 'Your numbers', 'list', 'The only numbers it answers.'],
            ]],
            'telegram' => ['Telegram', '', [
                ['TALIXIO_TELEGRAM_TOKEN', 'Bot token', 'secret', 'From @BotFather.'],
                ['TALIXIO_TELEGRAM_ALLOWED_IDS', 'Allowed chat ids', 'list', 'Message the bot: it replies with your id.'],
            ]],
            'search' => ['Web search and memory', '', [
                ['TALIXIO_SEARCH', 'Web search', 'select', 'auto: Claude\'s or Gemini\'s built-in search, otherwise the service set below.', ['auto' => 'auto', 'builtin' => 'built-in (Claude)', 'gemini' => 'Google via Gemini', 'brave' => 'Brave', 'tavily' => 'Tavily', 'searxng' => 'SearXNG', 'off' => 'off']],
                ['BRAVE_API_KEY', 'Brave Search key', 'secret', ''],
                ['TAVILY_API_KEY', 'Tavily key', 'secret', ''],
                ['SEARXNG_URL', 'SearXNG address', 'url', ''],
                ['TALIXIO_EMBEDDINGS', 'Search by meaning (embeddings)', 'select', 'Keyword search always works. After a change, memory is re-indexed in the background.', ['auto' => 'auto', 'gemini' => 'Gemini', 'openai' => 'OpenAI', 'voyage' => 'Voyage', 'ollama' => 'Ollama (local)', 'off' => 'off']],
                ['TALIXIO_EMBEDDINGS_MODEL', 'Embeddings model', 'text', 'Empty = the service\'s default. Ollama: qwen3-embedding:0.6b (recommended, multilingual), embeddinggemma, bge-m3. Install with install.sh --with-ollama; a new model must be pulled first (ollama pull …).'],
                ['TALIXIO_EMBEDDINGS_DIMS', 'Vector size', 'number', 'Empty = automatic (512 for models built for it: faster search, nearly the same quality). 0 = full size. Changing it re-indexes memory in the background.'],
                ['VOYAGE_API_KEY', 'Voyage key', 'secret', ''],
                ['TALIXIO_OLLAMA_URL', 'Ollama address', 'url', ''],
            ]],
            'tasks' => ['Tasks', 'The tasks board of each space.', [
                ['TALIXIO_TASK_REMINDER', 'Reminder the day before a due date', 'text', 'At this time (e.g. 18:00), a notification lists the tasks due tomorrow, per space. Empty = off.'],
                ['TALIXIO_TASKS_GOOGLE', 'Tasks marked "show in my calendar" go to Google Calendar', 'select', 'When Google Calendar is connected: added as all-day events (start to due date) and kept up to date. Otherwise use the private calendar link (Tasks → Calendar).', ['1' => 'on', '0' => 'off']],
            ]],
            'audio' => ['Audio, meetings and library', '', [
                ['TALIXIO_TRANSCRIBE', 'Transcription', 'select', 'auto: Gemini when its key is set, else local when whisper.cpp is installed. Local: the audio never leaves this server, but it is slower (about half the recording\'s length) and has no speaker names (install with sudo ./install.sh --with-whisper).', ['auto' => 'auto', 'gemini' => 'Gemini', 'openai' => 'OpenAI', 'local' => 'Local (whisper.cpp, on this server)', 'off' => 'off']],
                ['TALIXIO_TRANSCRIBE_MODEL', 'Transcription model', 'text', 'Empty = automatic. Local: large-v3-turbo-q5_0 (default), medium, small, or the path of a model file.'],
                ['TALIXIO_MEETING_TIER', 'Tier for meeting summaries', 'select', '', ['standard' => 'standard', 'deep' => 'deep', 'fast' => 'fast']],
                ['TALIXIO_VOICE_NOTE_MAX_SECONDS', 'Longer than this (seconds) = a meeting', 'number', ''],
                ['TALIXIO_AUTO_FILE', 'Filing new documents (Inbox)', 'select', 'The folder is guessed from your documents already filed (free), the fast model helps when unsure or to give a clear name.', ['suggest' => 'suggest, I confirm', 'auto' => 'file the obvious ones myself', 'off' => 'off']],
                ['TALIXIO_VISION_INDEX', 'Read images and scanned PDFs', 'select', 'Library: the fast model describes photos and transcribes scans so they can be searched (small cost per image or page).', ['1' => 'on', '0' => 'off']],
            ]],
            'browser' => ['Browser and approvals', '', [
                ['TALIXIO_BROWSER', 'Browser', 'select', 'Installed with install.sh --with-browser.', ['auto' => 'on when installed', 'off' => 'off']],
                ['TALIXIO_BROWSER_LANG', 'Browser language', 'text', 'fr-FR'],
                ['TALIXIO_BROWSER_ALLOW_PRIVATE', 'Allow local network addresses', 'bool', 'Not recommended: a web page could reach your devices.'],
                ['TALIXIO_BROWSER_NO_SANDBOX', 'Disable Chromium\'s sandbox', 'bool', 'Only if Chromium won\'t start otherwise.'],
                ['TALIXIO_APPROVAL_WAIT_SECONDS', 'Wait for an approval (seconds)', 'number', 'For a web form being filled in.'],
                ['TALIXIO_AUTO_APPROVE', 'Actions without approval', 'multi', 'Not recommended: you won\'t be asked for these any more.', ['calendar_add' => 'Add to calendar', 'mail_send' => 'Send email as you', 'browser_submit' => 'Submit a web form']],
            ]],
            'shell' => ['Sandboxed shell', 'A Linux sandbox for the assistant (bubblewrap, installed with install.sh --with-shell): it runs commands and Python to compute, convert or analyse files. It sees only the system programs and its own work folder, not your server, data or keys.', [
                ['TALIXIO_SHELL', 'Commands', 'select', '"Ask me" shows each command and waits for your approval (in the app, or "yes N" in a chat).', ['off' => 'off', 'ask' => 'ask me before each command', 'auto' => 'run without asking (sandboxed)']],
                ['TALIXIO_SHELL_NETWORK', 'Internet access in the sandbox', 'bool', 'To install Python packages (pip) or download data. Off is safer.'],
                ['TALIXIO_SHELL_TIMEOUT', 'Time limit per command (seconds)', 'number', ''],
            ]],
            'maintenance' => ['Learning, backups, updates', '', [
                ['TALIXIO_MIND', 'Character and values', 'bool', 'Learns by itself from your history what matters to you, how you decide, and what kind of assistant to be; it weighs this in its choices (Memory page). A few cents a day.'],
                ['TALIXIO_LEARN', 'Learn from tasks (skills)', 'bool', 'After a multi-step task, a small agent decides whether to keep it (max $0.05).'],
                ['TALIXIO_LEARN_MIN_STEPS', 'Minimum steps to learn', 'number', ''],
                ['TALIXIO_BACKUP_KEEP', 'Backups kept', 'number', 'One per night. 0 = no backups.'],
                ['TALIXIO_BACKUP_FILES', 'Include received files', 'bool', 'Recordings, PDFs…'],
                ['TALIXIO_BACKUP_DIR', 'Backup folder', 'text', 'Empty = /var/lib/talixio/backups. Use another disk to survive losing the VM.'],
                ['TALIXIO_AUTO_UPDATE', 'Install updates automatically at night', 'bool', 'Otherwise you get a notification and press "Update".'],
            ]],
            'advanced' => ['Advanced', '', [
                ['TALIXIO_CLAUDE_BIN', 'Claude Code program', 'text', ''],
                ['TALIXIO_SIGNAL_CLI', 'signal-cli program', 'text', ''],
                ['TALIXIO_CHROME_BIN', 'Chromium/Chrome program', 'text', ''],
                ['TALIXIO_RECALL_MIN_SIMILARITY', 'Automatic recall threshold', 'number', 'Between 0 and 1.'],
            ]],
        ];
    }

    private const EFFORT_CHOICES = ['' => 'default', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'very high', 'max' => 'max'];

    /** Defaults used by Config when nothing is set (shown in the app as placeholders). */
    public const DEFAULTS = [
        'TALIXIO_NAME' => 'Talixio', 'TALIXIO_LANG' => 'auto', 'TALIXIO_TIMEZONE' => 'UTC', 'TALIXIO_HISTORY_MESSAGES' => '40', 'TALIXIO_HISTORY_TOKENS' => '12000', 'TALIXIO_PROVIDER' => 'anthropic',
        'TALIXIO_DEEP_THINKING_TOKENS' => '8000', 'TALIXIO_BUDGET_DAILY_USD' => '0', 'TALIXIO_BUDGET_MONTHLY_USD' => '0', 'TALIXIO_MAX_PARALLEL_JOBS' => '3',
        'TALIXIO_JOB_MAX_USD' => '2.00', 'TALIXIO_JOB_MAX_USD_LIMIT' => '10', 'TALIXIO_JOB_EFFORT' => 'medium', 'TALIXIO_NOTIFY' => 'webpush,email,signal,telegram,ntfy', 'TALIXIO_HEARTBEAT_MINUTES' => '0', 'TALIXIO_TASK_REMINDER' => '18:00', 'TALIXIO_TASKS_GOOGLE' => '1',
        'TALIXIO_SMTP_PORT' => '587', 'TALIXIO_SMTP_SECURITY' => 'auto', 'TALIXIO_IMAP_PORT' => '993', 'TALIXIO_EMAIL_REQUIRE_AUTH' => '1',
        'TALIXIO_MAILBOX_IMAP_PORT' => '993', 'TALIXIO_MAILBOX_SMTP_PORT' => '465', 'TALIXIO_SEARCH' => 'auto', 'TALIXIO_EMBEDDINGS' => 'auto',
        'TALIXIO_OLLAMA_URL' => 'http://127.0.0.1:11434', 'TALIXIO_EMBEDDINGS_DIMS' => '', 'TALIXIO_TRANSCRIBE' => 'auto', 'TALIXIO_MEETING_TIER' => 'standard', 'TALIXIO_VOICE_NOTE_MAX_SECONDS' => '180', 'TALIXIO_VISION_INDEX' => '1',
        'TALIXIO_BROWSER' => 'auto', 'TALIXIO_BROWSER_LANG' => 'en-US', 'TALIXIO_BROWSER_ALLOW_PRIVATE' => '0', 'TALIXIO_BROWSER_NO_SANDBOX' => '0',
        'TALIXIO_APPROVAL_WAIT_SECONDS' => '600', 'TALIXIO_LEARN' => '1', 'TALIXIO_MIND' => '1', 'TALIXIO_GEMINI_PAID' => '0', 'TALIXIO_NOTIFY_NEUTRAL' => '0', 'TALIXIO_LEARN_MIN_STEPS' => '4', 'TALIXIO_BACKUP_KEEP' => '7',
        'TALIXIO_BACKUP_FILES' => '1', 'TALIXIO_AUTO_UPDATE' => '0', 'TALIXIO_CLAUDE_BIN' => 'claude', 'TALIXIO_SIGNAL_CLI' => 'signal-cli',
        'TALIXIO_RECALL_MIN_SIMILARITY' => '0.5', 'TALIXIO_SHELL' => 'off', 'TALIXIO_AUTO_FILE' => 'suggest', 'TALIXIO_MAIL_TRIAGE' => '1', 'TALIXIO_SHELL_NETWORK' => '0', 'TALIXIO_SHELL_TIMEOUT' => '120', 'TALIXIO_DEEP_REASONING_EFFORT' => '',
    ];

    /** @return array<string,array> key => field */
    public static function fields(): array
    {
        $out = [];
        foreach (self::schema() as $g => [, , $fields]) foreach ($fields as $f) $out[$f[0]] = ['key' => $f[0], 'label' => $f[1], 'type' => $f[2], 'help' => $f[3] ?? '', 'options' => $f[4] ?? null, 'group' => $g];
        return $out;
    }

    public static function file(string $dataDir): string { return $dataDir . '/settings.json'; }

    public static function load(string $dataDir): array
    {
        $d = json_decode((string)@file_get_contents(self::file($dataDir)), true);
        return is_array($d) ? array_filter($d, 'is_string') : [];
    }

    /** @var array<string,string|false> environment values before overrides, to restore when an override is removed */
    private static array $original = [];

    /** Put the saved overrides into the process environment (called by Config). */
    public static function apply(string $dataDir): void
    {
        $over = self::load($dataDir);
        foreach (self::$original as $k => $orig) {
            if (!array_key_exists($k, $over)) { putenv($orig === false ? $k : "$k=$orig"); unset(self::$original[$k]); }
        }
        foreach ($over as $k => $v) {
            if (!isset(self::fields()[$k]) && $k !== 'TALIXIO_WEB_PASSWORD') continue; // the hash and the TOTP secret stay out of the environment
            if (!array_key_exists($k, self::$original)) self::$original[$k] = getenv($k);
            putenv("$k=$v");
        }
    }

    /** What the web app shows: values (secrets masked) and where they come from. */
    public static function describe(string $dataDir): array
    {
        $over = self::load($dataDir);
        $groups = [];
        foreach (self::schema() as $g => [$title, $intro, $fields]) {
            $items = [];
            foreach ($fields as $f) {
                [$key, $label, $type] = $f;
                $value = (string)(getenv($key) !== false ? getenv($key) : '');
                $source = array_key_exists($key, $over) ? 'app' : ((self::$original[$key] ?? getenv($key)) ? 'file' : 'default');
                $items[] = ['key' => $key, 'label' => $label, 'type' => $type, 'help' => $f[3] ?? '', 'options' => $f[4] ?? null, 'default' => self::DEFAULTS[$key] ?? '',
                    'value' => $type === 'secret' ? '' : $value, 'set' => $value !== '', 'source' => $source,
                    'hint' => $type === 'secret' && $value !== '' ? '•••• ' . (mb_strlen($value) > 12 ? mb_substr($value, -4) : '') : ''];
            }
            $groups[] = ['id' => $g, 'title' => $title, 'intro' => $intro, 'fields' => $items];
        }
        return $groups;
    }

    /**
     * Save changes from the web app. $changes: key => value; for secrets an empty value means "unchanged";
     * $reset: keys to remove from the app's overrides (back to the config file's value).
     */
    public static function save(string $dataDir, array $changes, array $reset = []): array
    {
        $fields = self::fields();
        $over = self::load($dataDir);
        $errors = [];
        foreach ($changes as $k => $v) {
            if (!isset($fields[$k])) { $errors[$k] = 'unknown setting'; continue; }
            $f = $fields[$k];
            $v = is_array($v) ? implode(',', array_map('strval', $v)) : trim((string)$v);
            if ($f['type'] === 'secret' && $v === '') continue;
            if ($f['type'] === 'number' && $v !== '' && !is_numeric($v)) { $errors[$k] = 'a number is expected'; continue; }
            if ($f['type'] === 'bool') $v = in_array(strtolower($v), ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
            if ($f['type'] === 'select' && !array_key_exists($v, $f['options'])) { $errors[$k] = 'not one of the choices'; continue; }
            if ($f['type'] === 'multi') {
                $vals = array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
                if (array_diff($vals, array_keys($f['options']))) { $errors[$k] = 'not one of the choices'; continue; }
                $v = $vals ? implode(',', $vals) : 'none';
            }
            if ($f['type'] === 'list') $v = implode(',', array_filter(array_map('trim', preg_split('/[,\n]+/', $v)), 'strlen'));
            if ($f['type'] === 'url' && $v !== '' && !preg_match('#^https?://#i', $v)) { $errors[$k] = 'an http(s) address is expected'; continue; }
            if ($k === 'TALIXIO_TIMEZONE' && $v !== '' && !in_array($v, \DateTimeZone::listIdentifiers(), true)) { $errors[$k] = 'unknown time zone, e.g. Europe/Paris'; continue; }
            if (preg_match('/[\r\n]/', $v)) { $errors[$k] = 'a single line'; continue; }
            $over[$k] = $v;
        }
        foreach ($reset as $k) unset($over[$k]);
        if (!$errors) self::write($dataDir, $over);
        return $errors;
    }

    /** Only a hash of the app password is kept (and the plain one from older versions is removed). */
    public static function setPassword(string $dataDir, string $password): void
    {
        $over = self::load($dataDir);
        unset($over['TALIXIO_WEB_PASSWORD']);
        $over['TALIXIO_WEB_PASSWORD_HASH'] = password_hash($password, PASSWORD_DEFAULT);
        self::write($dataDir, $over);
    }

    /** Two-step sign-in secret ('' turns it off). */
    public static function setTotp(string $dataDir, string $secret): void
    {
        $over = self::load($dataDir);
        if ($secret === '') unset($over['TALIXIO_TOTP_SECRET']); else $over['TALIXIO_TOTP_SECRET'] = $secret;
        self::write($dataDir, $over);
    }

    public static function write(string $dataDir, array $over): void
    {
        ksort($over);
        $tmp = self::file($dataDir) . '.tmp';
        file_put_contents($tmp, json_encode($over, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        chmod($tmp, 0600);
        rename($tmp, self::file($dataDir));
    }
}
