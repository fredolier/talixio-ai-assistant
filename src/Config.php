<?php
declare(strict_types=1);
namespace Talixio;

/** All settings come from environment variables (see config/talixio.env.example). */
final class Config
{
    /** Where the app is served: '' at the root of the site, '/fred' when several instances share one address (TALIXIO_BASE_PATH). */
    public static function basePath(): string
    {
        $p = trim((string)getenv('TALIXIO_BASE_PATH'), " /");
        return $p === '' || !preg_match('#^[A-Za-z0-9._~-]+(/[A-Za-z0-9._~-]+)*$#', $p) ? '' : '/' . $p;
    }

    /** One of several assistants on a shared server (the Docker stack): its owner is not the server's administrator. */
    public static function inFleet(): bool { return (string)getenv('TALIXIO_INSTANCE') !== ''; }

    public string $provider = 'anthropic';      // anthropic | openai | gemini | sovereign | claude-code | echo
    public string $sovereign = 'ovhcloud';        // the European provider (Sovereign::SERVICES)
    public string $sovereignKey = '';
    public string $sovereignUrl = '';
    public bool $mistralBatch = true;            // Mistral: background calls through the batch API (half price)
    public bool $sovereignOnly = false;          // never send anything to an AI or search service outside Europe
    public string $escalate = 'auto';            // auto | on | off: a stronger model takes over for scheduling, retries and after a failed tool
    public string $tools = 'auto';               // auto | all | focused: send only the tools a message seems to need
    /** @var array<string,Tier> */ public array $tiers = [];
    public string $anthropicKey = '';
    public string $anthropicBaseUrl = 'https://api.anthropic.com';
    public string $openaiKey = '';
    public string $geminiKey = '';               // usable for transcription, embeddings and search whatever the provider
    public string $geminiBaseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    public string $transcribe = 'auto';          // resolved to gemini | openai | local | off
    public string $transcribeModel = '';
    public string $meetingTier = 'standard';
    public int $voiceNoteMaxSeconds = 180;       // longer audio is treated as a meeting recording
    public bool $visionIndex = true;             // library: describe images and read scanned PDFs with the fast model
    public string $openaiBaseUrl = 'https://api.openai.com/v1';
    public string $claudeBin = 'claude';
    /** A second Claude subscription (AI account): the token from `claude setup-token`, given to the CLI instead of the server's login. */
    public string $claudeToken = '';
    /** The AI account this configuration is for ('' = the main settings). */
    public string $account = '';
    /** Admin keys that read what the provider really billed (Billing); optional, never used for model calls. */
    public string $anthropicAdminKey = '';
    public string $anthropicWorkspace = '';
    public string $openaiAdminKey = '';
    public string $openaiProject = '';
    public string $dataDir = 'data';
    public string $timezone = 'UTC';
    public string $name = 'Talixio';
    public int $historyMessages = 40;
    public int $historyTokens = 12000;
    public string $shell = 'off';
    public string $autoFile = 'suggest';
    public bool $mailTriage = true;
    public string $googleClientId = '';
    public string $googleClientSecret = '';
    public string $googleAuthUrl = 'https://accounts.google.com/o/oauth2/v2/auth';
    public string $googleTokenUrl = 'https://oauth2.googleapis.com/token';
    public string $googleRevokeUrl = 'https://oauth2.googleapis.com/revoke';
    public string $googleApiBase = 'https://www.googleapis.com/calendar/v3';
    public string $microsoftClientId = '';
    public string $microsoftClientSecret = '';
    public string $microsoftTenant = 'common';
    public string $microsoftLoginBase = 'https://login.microsoftonline.com';
    public string $microsoftApiBase = 'https://graph.microsoft.com/v1.0';
    public string $spotifyClientId = '';
    public string $spotifyClientSecret = '';
    public string $spotifyDevice = '';
    public string $spotifyAuthUrl = 'https://accounts.spotify.com/authorize';
    public string $spotifyTokenUrl = 'https://accounts.spotify.com/api/token';
    public string $spotifyApiBase = 'https://api.spotify.com/v1';
    public bool $shellNetwork = false;
    public int $shellTimeout = 120;
    public string $webPassword = '';
    public string $telegramToken = '';
    /** @var int[] */ public array $telegramAllowed = [];
    public string $ntfyUrl = '';
    public string $ntfyToken = '';
    public int $heartbeatMinutes = 0;
    public string $taskReminder = '18:00';
    public bool $tasksGoogle = true;
    public string $search = 'auto';             // resolved to builtin | brave | tavily | searxng | off
    public string $braveKey = '';
    public string $tavilyKey = '';
    public string $searxngUrl = '';
    public float $budgetDaily = 0.0;
    public float $budgetMonthly = 0.0;
    public float $eurUsd = 1.10;               // to compare bills in euros with prices in dollars
    public string $prices = '';
    public int $maxParallelJobs = 3;
    public float $jobMaxUsd = 2.0;               // default cost cap of a background job
    public float $jobMaxUsdLimit = 10.0;         // the most the assistant may give one job when asked
    public string $jobEffort = 'medium';         // default effort of background jobs (Tier::EFFORTS)
    public string $embeddings = 'off';           // resolved to ollama | openai | voyage | off
    public string $embeddingsModel = '';
    public int $embeddingsDims = -1;             // -1 = automatic, 0 = full size
    public string $ollamaUrl = 'http://127.0.0.1:11434';
    public string $voyageKey = '';
    public float $recallMinSim = 0.5;
    /** @var string[] channels for messages the assistant starts (ntfy, webpush, telegram, email, signal) */
    public array $notify = [];
    public string $smtpHost = '';
    public int $smtpPort = 587;
    public string $smtpSecurity = 'auto';       // auto | starttls | tls | none
    public string $smtpUser = '';
    public string $smtpPassword = '';
    public string $smtpAuth = 'password';       // password | microsoft (OAuth, SMTP and IMAP)
    public string $emailFrom = '';
    public string $emailTo = '';
    /** @var string[] */ public array $emailAllowed = [];
    public bool $emailRequireAuth = true;
    /** The name your mail server signs its Authentication-Results with (e.g. mx.example.net): others are ignored. */
    public string $emailAuthServ = '';
    public string $imapHost = '';
    public int $imapPort = 993;
    public string $imapUser = '';
    public string $imapPassword = '';
    public string $imapFolder = 'INBOX';
    public string $imapSecurity = 'auto';
    public string $vapidSubject = '';
    /** @var string[] iCal subscription URLs (read-only) */ public array $calendarIcs = [];
    public string $caldavUrl = '';
    public string $caldavUser = '';
    public string $caldavPassword = '';
    public string $mailboxImapHost = '';
    public int $mailboxImapPort = 993;
    public string $mailboxImapSecurity = 'auto';
    public string $mailboxSmtpHost = '';
    public int $mailboxSmtpPort = 465;
    public string $mailboxSmtpSecurity = 'auto';
    public string $mailboxUser = '';
    public string $mailboxPassword = '';
    public string $mailboxAuth = 'password';    // password | microsoft
    public string $mailboxFrom = '';
    public string $mailboxDrafts = 'Drafts';
    public string $mailboxSent = 'Sent';
    /** @var string[] tools that run without asking (default: none) */ public array $autoApprove = [];
    public string $signalCli = 'signal-cli';
    public string $browser = 'auto';             // auto (on if Chromium/Chrome is installed) | off
    public string $chromeBin = '';
    public bool $browserNoSandbox = false;
    public bool $browserAllowPrivate = false;    // let the browser open local/LAN addresses (off: safer)
    public string $browserLang = 'en-US';
    public int $approvalWaitSeconds = 600;
    public bool $autoUpdate = false;
    public string $publicUrl = '';              // the app's address for links sent outside (shares); '' = as last opened
    public string $lang = 'auto';                // interface language: auto (the browser's) | en | fr
    public bool $geminiPaid = false;             // the Gemini key is on a paid plan (data not used for training): Privacy page
    public bool $notifyNeutral = false;          // notifications never carry the content ("New answer in X")
    public bool $mind = true;                    // learn values, ways and a character from the history (Mind)
    public bool $learn = true;                   // write skills from multi-step tasks (background, fast tier)
    public int $learnMinSteps = 4;
    public string $backupDir = '';
    public int $backupKeep = 7;
    public bool $backupFiles = true;       // how long a paused browser action waits for your approval
    public string $signalNumber = '';
    /** @var string[] */ public array $signalAllowed = [];

    public static function fromEnv(): self
    {
        $dataDir = rtrim((string)(getenv('TALIXIO_DATA_DIR') ?: 'data'), '/');
        if (!str_starts_with($dataDir, '/')) $dataDir = getcwd() . '/' . $dataDir;
        Settings::apply($dataDir); // values saved in the web app override the config file
        $e = static fn(string $k, string $d = ''): string => ($v = getenv($k)) !== false && $v !== '' ? trim($v) : $d;
        $c = new self();
        $c->provider = strtolower($e('TALIXIO_PROVIDER', 'anthropic'));
        [$fast, $std, $deep] = self::defaultModels($c->provider); // gemini, sovereign: chosen from the models your key can use (GeminiModels, Sovereign)
        $std = $e('TALIXIO_MODEL', $std);
        $c->tiers = [
            'fast' => new Tier('fast', $e('TALIXIO_MODEL_FAST', $e('TALIXIO_CHEAP_MODEL', $fast)), 6),
            'standard' => new Tier('standard', $std, 12),
            'deep' => new Tier('deep', $e('TALIXIO_MODEL_DEEP', $deep), 25,
                (int)$e('TALIXIO_DEEP_THINKING_TOKENS', '8000'), self::effort($e('TALIXIO_DEEP_REASONING_EFFORT', in_array($c->provider, ['openai', 'sovereign'], true) ? '' : 'high'))),
        ];
        $c->anthropicKey = $e('ANTHROPIC_API_KEY');
        $c->anthropicAdminKey = $e('ANTHROPIC_ADMIN_KEY');
        $c->anthropicWorkspace = $e('ANTHROPIC_WORKSPACE_ID');
        $c->openaiAdminKey = $e('OPENAI_ADMIN_KEY');
        $c->openaiProject = $e('OPENAI_PROJECT_ID');
        $c->anthropicBaseUrl = rtrim($e('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/');
        $c->openaiKey = $e('OPENAI_API_KEY');
        $c->openaiBaseUrl = rtrim($e('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/');
        $c->geminiKey = $e('GEMINI_API_KEY');
        $c->geminiBaseUrl = preg_replace('#/openai$#', '', rtrim($e('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'));
        if ($c->provider === 'gemini') { // Gemini through its OpenAI-compatible endpoint
            $c->openaiKey = $c->geminiKey;
            $c->openaiBaseUrl = $c->geminiBaseUrl . '/openai';
        }
        $c->sovereign = strtolower($e('TALIXIO_SOVEREIGN', 'ovhcloud'));
        if (!isset(Sovereign::SERVICES[$c->sovereign])) $c->sovereign = 'ovhcloud';
        $c->sovereignKey = $e('TALIXIO_SOVEREIGN_KEY');
        $c->sovereignUrl = rtrim($e('TALIXIO_SOVEREIGN_URL', Sovereign::SERVICES[$c->sovereign]['url']), '/');
        $c->sovereignOnly = $e('TALIXIO_SOVEREIGN_ONLY', '0') === '1';
        $c->mistralBatch = $e('TALIXIO_MISTRAL_BATCH', '1') === '1';
        $c->tools = in_array($t = $e('TALIXIO_TOOLS', 'auto'), ['auto', 'all', 'focused'], true) ? $t : 'auto';
        $c->escalate = in_array($t = $e('TALIXIO_ESCALATE', 'auto'), ['auto', 'on', 'off'], true) ? $t : 'auto';
        if ($c->provider === 'sovereign') { // a European provider through its OpenAI-compatible endpoint
            $c->openaiKey = $c->sovereignKey;
            $c->openaiBaseUrl = $c->sovereignUrl;
        }
        $euFirst = $c->sovereignReady() && ($c->provider === 'sovereign' || $c->sovereignOnly); // its key does everything
        $c->transcribe = strtolower($e('TALIXIO_TRANSCRIBE', 'auto'));
        if ($c->transcribe === 'auto') {
            $c->transcribe = match (true) {
                $euFirst => 'sovereign',
                $c->sovereignOnly => Transcriber::whisperReady() ? 'local' : 'off',
                $c->geminiKey !== '' => 'gemini',
                $c->provider === 'openai' && $c->openaiKey !== '' && str_contains($c->openaiBaseUrl, 'api.openai.com') => 'openai',
                Transcriber::whisperReady() => 'local', // install.sh --with-whisper, or the Docker image with a model in its volume
                default => 'off',
            };
        }
        $c->transcribeModel = $e('TALIXIO_TRANSCRIBE_MODEL', match ($c->transcribe) { 'openai' => 'gpt-4o-transcribe', 'sovereign' => Sovereign::SERVICES[$c->sovereign]['transcribe'], default => '' });
        $c->meetingTier = $e('TALIXIO_MEETING_TIER', 'standard');
        $c->voiceNoteMaxSeconds = (int)$e('TALIXIO_VOICE_NOTE_MAX_SECONDS', '180');
        $c->visionIndex = $e('TALIXIO_VISION_INDEX', '1') !== '0';
        $c->claudeBin = $e('TALIXIO_CLAUDE_BIN', 'claude');
        $c->dataDir = rtrim($e('TALIXIO_DATA_DIR', 'data'), '/');
        if (!str_starts_with($c->dataDir, '/')) $c->dataDir = getcwd() . '/' . $c->dataDir; // child processes run elsewhere
        $c->timezone = $e('TALIXIO_TIMEZONE', 'UTC');
        $c->name = $e('TALIXIO_INSTANCE_NAME', $e('TALIXIO_NAME', 'Talixio')); // on a shared server, the admin page names it
        $c->historyMessages = max(6, (int)$e('TALIXIO_HISTORY_MESSAGES', '40'));
        $c->historyTokens = max(2000, (int)$e('TALIXIO_HISTORY_TOKENS', '12000'));
        $c->googleClientId = trim($e('TALIXIO_GOOGLE_CLIENT_ID'));
        $c->googleClientSecret = trim($e('TALIXIO_GOOGLE_CLIENT_SECRET'));
        foreach (['googleAuthUrl' => 'TALIXIO_GOOGLE_AUTH_URL', 'googleTokenUrl' => 'TALIXIO_GOOGLE_TOKEN_URL', 'googleRevokeUrl' => 'TALIXIO_GOOGLE_REVOKE_URL', 'googleApiBase' => 'TALIXIO_GOOGLE_API_BASE'] as $prop => $var) {
            if ($e($var) !== '') $c->$prop = rtrim($e($var), '/'); // tests point these at a fake server
        }
        $c->microsoftClientId = trim($e('TALIXIO_MICROSOFT_CLIENT_ID'));
        $c->microsoftClientSecret = trim($e('TALIXIO_MICROSOFT_CLIENT_SECRET'));
        $c->microsoftTenant = trim($e('TALIXIO_MICROSOFT_TENANT', 'common')) ?: 'common';
        foreach (['microsoftLoginBase' => 'TALIXIO_MICROSOFT_LOGIN_BASE', 'microsoftApiBase' => 'TALIXIO_MICROSOFT_API_BASE'] as $prop => $var) {
            if ($e($var) !== '') $c->$prop = rtrim($e($var), '/');
        }
        $c->spotifyClientId = trim($e('TALIXIO_SPOTIFY_CLIENT_ID'));
        $c->spotifyClientSecret = trim($e('TALIXIO_SPOTIFY_CLIENT_SECRET'));
        $c->spotifyDevice = trim($e('TALIXIO_SPOTIFY_DEVICE'));
        foreach (['spotifyAuthUrl' => 'TALIXIO_SPOTIFY_AUTH_URL', 'spotifyTokenUrl' => 'TALIXIO_SPOTIFY_TOKEN_URL', 'spotifyApiBase' => 'TALIXIO_SPOTIFY_API_BASE'] as $prop => $var) {
            if ($e($var) !== '') $c->$prop = rtrim($e($var), '/');
        }
        $c->taskReminder = preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', trim($e('TALIXIO_TASK_REMINDER', '18:00'))) ? trim($e('TALIXIO_TASK_REMINDER', '18:00')) : '';
        $c->tasksGoogle = $e('TALIXIO_TASKS_GOOGLE', '1') !== '0';
        $c->mailTriage = $e('TALIXIO_MAIL_TRIAGE', '1') !== '0';
        $c->autoFile = in_array($e('TALIXIO_AUTO_FILE', 'suggest'), ['off', 'suggest', 'auto'], true) ? $e('TALIXIO_AUTO_FILE', 'suggest') : 'suggest';
        $c->shell = in_array($e('TALIXIO_SHELL', 'off'), ['off', 'ask', 'auto'], true) ? $e('TALIXIO_SHELL', 'off') : 'off';
        $c->shellNetwork = $e('TALIXIO_SHELL_NETWORK', '0') === '1';
        $c->shellTimeout = max(5, min(3600, (int)$e('TALIXIO_SHELL_TIMEOUT', '120')));
        $c->webPassword = $e('TALIXIO_WEB_PASSWORD');
        $c->telegramToken = $e('TALIXIO_TELEGRAM_TOKEN');
        $c->telegramAllowed = array_map('intval', array_filter(array_map('trim', explode(',', $e('TALIXIO_TELEGRAM_ALLOWED_IDS'))), 'strlen'));
        $c->ntfyUrl = $e('TALIXIO_NTFY_URL');
        $c->ntfyToken = $e('TALIXIO_NTFY_TOKEN');
        $c->heartbeatMinutes = (int)$e('TALIXIO_HEARTBEAT_MINUTES', '0');
        $c->braveKey = $e('BRAVE_API_KEY');
        $c->tavilyKey = $e('TAVILY_API_KEY');
        $c->searxngUrl = rtrim($e('SEARXNG_URL'), '/');
        $c->search = strtolower($e('TALIXIO_SEARCH', 'auto'));
        if ($c->search === 'auto') {
            $c->search = match (true) {
                $c->sovereignOnly => $c->searxngUrl !== '' ? 'searxng' : 'off', // the others are outside Europe
                $c->braveKey !== '' => 'brave',
                $c->tavilyKey !== '' => 'tavily',
                $c->searxngUrl !== '' => 'searxng',
                in_array($c->provider, ['anthropic', 'claude-code'], true) => 'builtin',
                $c->provider === 'gemini' => 'gemini',
                default => 'off',
            };
        }
        $c->budgetDaily = (float)$e('TALIXIO_BUDGET_DAILY_USD', '0');
        $c->budgetMonthly = (float)$e('TALIXIO_BUDGET_MONTHLY_USD', '0');
        $c->eurUsd = (float)$e('TALIXIO_EUR_USD', '1.10') ?: 1.10;
        $c->prices = $e('TALIXIO_PRICES');
        $c->maxParallelJobs = max(1, (int)$e('TALIXIO_MAX_PARALLEL_JOBS', '3'));
        $c->jobMaxUsd = max(0.0, (float)$e('TALIXIO_JOB_MAX_USD', '2.00'));
        $c->jobMaxUsdLimit = max($c->jobMaxUsd, (float)$e('TALIXIO_JOB_MAX_USD_LIMIT', '10'));
        $c->jobEffort = self::effort($e('TALIXIO_JOB_EFFORT', 'medium')) ?: 'medium';
        $c->ollamaUrl = rtrim($e('TALIXIO_OLLAMA_URL', 'http://127.0.0.1:11434'), '/');
        $c->voyageKey = $e('VOYAGE_API_KEY');
        $c->embeddings = strtolower($e('TALIXIO_EMBEDDINGS', 'auto'));
        if ($c->embeddings === 'auto') {
            $c->embeddings = match (true) {
                $euFirst => 'sovereign',
                $c->sovereignOnly => 'off',
                $c->voyageKey !== '' => 'voyage',
                $c->geminiKey !== '' => 'gemini',
                $c->openaiKey !== '' && str_contains($c->openaiBaseUrl, 'api.openai.com') => 'openai',
                default => 'off',
            };
        }
        $embedDefault = match ($c->embeddings) {
            'ollama' => 'qwen3-embedding:0.6b', 'openai' => 'text-embedding-3-small', 'voyage' => 'voyage-3.5-lite', 'gemini' => 'gemini-embedding-001',
            'sovereign' => Sovereign::SERVICES[$c->sovereign]['embed'], default => '',
        };
        $c->embeddingsModel = $e('TALIXIO_EMBEDDINGS_MODEL', $embedDefault);
        if ($c->embeddings !== 'ollama' && str_contains($c->embeddingsModel, ':')) $c->embeddingsModel = $embedDefault; // an Ollama model left from before the switch
        $c->embeddingsDims = $e('TALIXIO_EMBEDDINGS_DIMS') === '' ? -1 : (int)$e('TALIXIO_EMBEDDINGS_DIMS');
        $c->recallMinSim = (float)$e('TALIXIO_RECALL_MIN_SIMILARITY', $c->embeddings === 'ollama' && str_contains($c->embeddingsModel, 'qwen3') ? '0.45' : '0.5');
        $list = fn(string $v) => array_values(array_filter(array_map(fn($x) => strtolower(trim($x)), explode(',', $v)), 'strlen'));
        $c->smtpHost = $e('TALIXIO_SMTP_HOST');
        $c->smtpPort = (int)$e('TALIXIO_SMTP_PORT', '587');
        $c->smtpSecurity = strtolower($e('TALIXIO_SMTP_SECURITY', 'auto'));
        $c->smtpUser = $e('TALIXIO_SMTP_USER');
        $c->smtpPassword = $e('TALIXIO_SMTP_PASSWORD');
        $c->smtpAuth = strtolower($e('TALIXIO_SMTP_AUTH', 'password'));
        $c->emailFrom = $e('TALIXIO_EMAIL_FROM', $c->smtpUser);
        $c->emailTo = $e('TALIXIO_EMAIL_TO');
        $c->emailAllowed = $list($e('TALIXIO_EMAIL_ALLOWED', $c->emailTo));
        $c->emailRequireAuth = $e('TALIXIO_EMAIL_REQUIRE_AUTH', '1') !== '0';
        $c->emailAuthServ = $e('TALIXIO_EMAIL_AUTHSERV_ID');
        $c->imapHost = $e('TALIXIO_IMAP_HOST');
        $c->imapPort = (int)$e('TALIXIO_IMAP_PORT', '993');
        $c->imapUser = $e('TALIXIO_IMAP_USER', $c->smtpUser);
        $c->imapPassword = $e('TALIXIO_IMAP_PASSWORD', $c->smtpPassword);
        $c->imapFolder = $e('TALIXIO_IMAP_FOLDER', 'INBOX');
        $c->imapSecurity = strtolower($e('TALIXIO_IMAP_SECURITY', 'auto'));
        $c->vapidSubject = $e('TALIXIO_VAPID_SUBJECT', 'mailto:' . ($c->emailTo ?: 'admin@localhost'));
        $c->calendarIcs = array_values(array_filter(array_map('trim', explode(',', $e('TALIXIO_CALENDAR_ICS'))), 'strlen'));
        $c->caldavUrl = $e('TALIXIO_CALDAV_URL');
        $c->caldavUser = $e('TALIXIO_CALDAV_USER');
        $c->caldavPassword = $e('TALIXIO_CALDAV_PASSWORD');
        $c->mailboxImapHost = $e('TALIXIO_MAILBOX_IMAP_HOST');
        $c->mailboxImapPort = (int)$e('TALIXIO_MAILBOX_IMAP_PORT', '993');
        $c->mailboxImapSecurity = strtolower($e('TALIXIO_MAILBOX_IMAP_SECURITY', 'auto'));
        $c->mailboxSmtpHost = $e('TALIXIO_MAILBOX_SMTP_HOST', $c->mailboxImapHost);
        $c->mailboxSmtpPort = (int)$e('TALIXIO_MAILBOX_SMTP_PORT', '465');
        $c->mailboxSmtpSecurity = strtolower($e('TALIXIO_MAILBOX_SMTP_SECURITY', 'auto'));
        $c->mailboxUser = $e('TALIXIO_MAILBOX_USER');
        $c->mailboxPassword = $e('TALIXIO_MAILBOX_PASSWORD');
        $c->mailboxAuth = strtolower($e('TALIXIO_MAILBOX_AUTH', 'password'));
        $c->mailboxFrom = $e('TALIXIO_MAILBOX_FROM', $c->mailboxUser);
        $c->mailboxDrafts = $e('TALIXIO_MAILBOX_DRAFTS', 'Drafts');
        $c->mailboxSent = $e('TALIXIO_MAILBOX_SENT', 'Sent');
        $c->autoApprove = $list($e('TALIXIO_AUTO_APPROVE'));
        $c->signalCli = $e('TALIXIO_SIGNAL_CLI', 'signal-cli');
        $c->browser = strtolower($e('TALIXIO_BROWSER', 'auto'));
        $c->chromeBin = $e('TALIXIO_CHROME_BIN');
        $c->browserNoSandbox = $e('TALIXIO_BROWSER_NO_SANDBOX', '0') === '1';
        $c->browserAllowPrivate = $e('TALIXIO_BROWSER_ALLOW_PRIVATE', '0') === '1';
        $c->browserLang = $e('TALIXIO_BROWSER_LANG', 'en-US');
        $c->approvalWaitSeconds = (int)$e('TALIXIO_APPROVAL_WAIT_SECONDS', '600');
        $c->autoUpdate = $e('TALIXIO_AUTO_UPDATE', '0') === '1';
        $c->learn = $e('TALIXIO_LEARN', '1') !== '0';
        $c->mind = $e('TALIXIO_MIND', '1') !== '0';
        $c->geminiPaid = $e('TALIXIO_GEMINI_PAID', '0') === '1';
        $c->notifyNeutral = $e('TALIXIO_NOTIFY_NEUTRAL', '0') === '1';
        $c->publicUrl = rtrim($e('TALIXIO_PUBLIC_URL'), '/');
        $c->lang = in_array($l = strtolower($e('TALIXIO_LANG', 'auto')), ['auto', ...I18n::LANGS], true) ? $l : 'auto';
        $c->learnMinSteps = (int)$e('TALIXIO_LEARN_MIN_STEPS', '4');
        $c->backupDir = rtrim($e('TALIXIO_BACKUP_DIR'), '/');
        $c->backupKeep = (int)$e('TALIXIO_BACKUP_KEEP', '7');
        $c->backupFiles = $e('TALIXIO_BACKUP_FILES', '1') !== '0';
        $c->signalNumber = $e('TALIXIO_SIGNAL_NUMBER');
        $c->signalAllowed = array_values(array_filter(array_map('trim', explode(',', $e('TALIXIO_SIGNAL_ALLOWED'))), 'strlen'));
        $c->notify = $list($e('TALIXIO_NOTIFY', 'ntfy,webpush,telegram,email,signal'));
        date_default_timezone_set($c->timezone);
        return $c;
    }

    /** Provider defaults for the three tiers ('' = chosen from the models the key can use). */
    public static function defaultModels(string $provider): array
    {
        return match ($provider) {
            'anthropic' => ['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'],
            'openai' => ['gpt-4.1-mini', 'gpt-4.1', 'gpt-4.1'],
            'claude-code' => ['haiku', 'sonnet', 'opus'],
            default => ['', '', ''],
        };
    }

    /**
     * This configuration with the model of an AI account (Accounts): its provider, key, address and models. What is not
     * the conversation model stays as set: transcription, search by meaning, the European-only rule, budgets.
     */
    public function forAccount(array $a): self
    {
        $c = clone $this;
        $kind = (string)$a['kind'];
        $c->provider = $kind; $c->account = (string)$a['id'];
        $def = self::defaultModels($kind);
        $m = fn(string $k, int $i) => trim((string)($a['models'][$k] ?? '')) ?: $def[$i];
        $deep = $this->tiers['deep'] ?? null;
        $c->tiers = [
            'fast' => new Tier('fast', $m('fast', 0), 6),
            'standard' => new Tier('standard', $m('standard', 1), 12),
            'deep' => new Tier('deep', $m('deep', 2), 25, $deep ? $deep->thinkingTokens : 8000, in_array($kind, ['openai', 'sovereign'], true) ? '' : 'high'),
        ];
        $key = (string)($a['key'] ?? ''); $url = rtrim((string)($a['url'] ?? ''), '/');
        switch ($kind) {
            case 'anthropic':
                $c->anthropicKey = $key; $c->anthropicBaseUrl = $url ?: 'https://api.anthropic.com';
                break;
            case 'openai':
                $c->openaiKey = $key; $c->openaiBaseUrl = $url ?: 'https://api.openai.com/v1';
                break;
            case 'gemini':
                $c->openaiKey = $key; $c->openaiBaseUrl = ($url ?: $this->geminiBaseUrl) . '/openai';
                $c->geminiPaid = !empty($a['paid']);
                break;
            case 'sovereign': // transcription and search by meaning keep the main European settings (Transcriber, Embeddings use mainCfg)
                $c->sovereign = isset(Sovereign::SERVICES[$a['service'] ?? '']) ? $a['service'] : 'ovhcloud';
                $c->sovereignKey = $key; $c->sovereignUrl = $url ?: Sovereign::SERVICES[$c->sovereign]['url'];
                $c->openaiKey = $key; $c->openaiBaseUrl = $c->sovereignUrl;
                break;
            case 'claude-code':
                $c->claudeToken = (string)($a['token'] ?? '');
                break;
        }
        // the model's own web search follows the provider
        $builtin = in_array($kind, ['anthropic', 'claude-code'], true);
        if ($c->search === 'builtin' && !$builtin) $c->search = $kind === 'gemini' && $c->geminiKey !== '' ? 'gemini' : 'off';
        elseif ($c->search === 'off' && $builtin && !$c->sovereignOnly && in_array(strtolower((string)getenv('TALIXIO_SEARCH')), ['', 'auto'], true)) $c->search = 'builtin';
        return $c;
    }

    public function __clone() { foreach ($this->tiers as $k => $t) $this->tiers[$k] = clone $t; }

    private static function effort(string $v): string { return in_array($v = strtolower($v), Tier::EFFORTS, true) ? $v : ''; }

    public function tier(string $name): Tier { return $this->tiers[$name] ?? $this->tiers['standard']; }
    public function embeddingsBackend(): string { return $this->embeddings; }
    /** Why no model can answer yet ('no-key', 'no-claude-code'), or null when the chosen provider is set up. */
    public function modelProblem(): ?string
    {
        return match ($this->provider) {
            'echo' => null,
            'anthropic' => $this->anthropicKey === '' ? 'no-key' : null,
            'gemini' => $this->openaiKey === '' ? 'no-key' : null, // the Gemini key, set as the OpenAI-compatible one
            'sovereign' => $this->sovereignReady() ? null : 'no-key',
            'openai' => $this->openaiKey === '' && str_contains($this->openaiBaseUrl, 'api.openai.com') ? 'no-key' : null, // a local server needs none
            'claude-code' => !ClaudeLogin::installed($this) ? 'no-claude-code'
                : ($this->claudeToken === '' && (getenv('CLAUDE_CODE_OAUTH_TOKEN') ?: '') === '' && $this->anthropicKey === '' && !is_file((getenv('HOME') ?: '') . '/.claude/.credentials.json') ? 'no-key' : null),
            default => null,
        };
    }

    public function filesDir(): string { return $this->dataDir . '/files'; }
    public function dbPath(): string { return $this->dataDir . '/talixio.db'; }
    public function workspaceDir(): string { return $this->dataDir . '/workspace'; }
    /** Models served by a local OpenAI-compatible server cost nothing. */
    public function localModels(): bool { return $this->provider === 'openai' && !str_contains($this->openaiBaseUrl, 'openai.com'); }
    /** Providers that speak the OpenAI chat completions API. */
    /** Whether a turn on the standard tier may move up to the deep model when it looks hard (Agent::escalate). Automatic: with a European provider, whose standard model is a small one. */
    public function escalates(): bool
    {
        if ($this->escalate === 'off' || $this->tiers['deep']->model === $this->tiers['standard']->model) return false;
        return $this->escalate === 'on' || $this->provider === 'sovereign';
    }

    /** The European provider has an address and, when it needs one, a key. */
    public function sovereignReady(): bool { return $this->sovereignUrl !== '' && ($this->sovereignKey !== '' || !Sovereign::info($this->sovereign)['key']); }
}
