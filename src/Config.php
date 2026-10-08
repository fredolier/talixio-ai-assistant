<?php
declare(strict_types=1);
namespace Talixio;

/** All settings come from environment variables (see config/talixio.env.example). */
final class Config
{
    public string $provider = 'anthropic';      // anthropic | openai | claude-code | echo
    /** @var array<string,Tier> */ public array $tiers = [];
    public string $anthropicKey = '';
    public string $anthropicBaseUrl = 'https://api.anthropic.com';
    public string $openaiKey = '';
    public string $geminiKey = '';               // usable for transcription, embeddings and search whatever the provider
    public string $geminiBaseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    public string $transcribe = 'auto';          // resolved to gemini | openai | off
    public string $transcribeModel = '';
    public string $meetingTier = 'standard';
    public int $voiceNoteMaxSeconds = 180;       // longer audio is treated as a meeting recording
    public string $openaiBaseUrl = 'https://api.openai.com/v1';
    public string $claudeBin = 'claude';
    public string $dataDir = 'data';
    public string $timezone = 'UTC';
    public string $name = 'Talixio';
    public int $historyMessages = 40;
    public string $webPassword = '';
    public string $telegramToken = '';
    /** @var int[] */ public array $telegramAllowed = [];
    public string $ntfyUrl = '';
    public string $ntfyToken = '';
    public int $heartbeatMinutes = 0;
    public string $search = 'auto';             // resolved to builtin | brave | tavily | searxng | off
    public string $braveKey = '';
    public string $tavilyKey = '';
    public string $searxngUrl = '';
    public float $budgetDaily = 0.0;
    public float $budgetMonthly = 0.0;
    public string $prices = '';
    public int $maxParallelJobs = 3;
    public float $jobMaxUsd = 0.5;
    public string $embeddings = 'off';           // resolved to ollama | openai | voyage | off
    public string $embeddingsModel = '';
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
    public string $emailFrom = '';
    public string $emailTo = '';
    /** @var string[] */ public array $emailAllowed = [];
    public bool $emailRequireAuth = true;
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
    public string $mailboxFrom = '';
    public string $mailboxDrafts = 'Drafts';
    public string $mailboxSent = 'Sent';
    /** @var string[] tools that run without asking (default: none) */ public array $autoApprove = [];
    public string $signalCli = 'signal-cli';
    public string $signalNumber = '';
    /** @var string[] */ public array $signalAllowed = [];

    public static function fromEnv(): self
    {
        $e = static fn(string $k, string $d = ''): string => ($v = getenv($k)) !== false && $v !== '' ? trim($v) : $d;
        $c = new self();
        $c->provider = strtolower($e('TALIXIO_PROVIDER', 'anthropic'));
        [$fast, $std, $deep] = match ($c->provider) {
            'anthropic' => ['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5'],
            'openai' => ['gpt-4.1-mini', 'gpt-4.1', 'gpt-4.1'],
            'claude-code' => ['haiku', 'sonnet', 'opus'],
            default => ['', '', ''], // gemini: chosen from the models your key can use (GeminiModels)
        };
        $std = $e('TALIXIO_MODEL', $std);
        $c->tiers = [
            'fast' => new Tier('fast', $e('TALIXIO_MODEL_FAST', $e('TALIXIO_CHEAP_MODEL', $fast)), 6),
            'standard' => new Tier('standard', $std, 12),
            'deep' => new Tier('deep', $e('TALIXIO_MODEL_DEEP', $deep), 25,
                (int)$e('TALIXIO_DEEP_THINKING_TOKENS', '8000'), $e('TALIXIO_DEEP_REASONING_EFFORT', $c->provider === 'gemini' ? 'high' : '')),
        ];
        $c->anthropicKey = $e('ANTHROPIC_API_KEY');
        $c->anthropicBaseUrl = rtrim($e('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/');
        $c->openaiKey = $e('OPENAI_API_KEY');
        $c->openaiBaseUrl = rtrim($e('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/');
        $c->geminiKey = $e('GEMINI_API_KEY');
        $c->geminiBaseUrl = preg_replace('#/openai$#', '', rtrim($e('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'), '/'));
        if ($c->provider === 'gemini') { // Gemini through its OpenAI-compatible endpoint
            $c->openaiKey = $c->geminiKey;
            $c->openaiBaseUrl = $c->geminiBaseUrl . '/openai';
        }
        $c->transcribe = strtolower($e('TALIXIO_TRANSCRIBE', 'auto'));
        if ($c->transcribe === 'auto') {
            $c->transcribe = match (true) {
                $c->geminiKey !== '' => 'gemini',
                $c->provider === 'openai' && $c->openaiKey !== '' && str_contains($c->openaiBaseUrl, 'api.openai.com') => 'openai',
                default => 'off',
            };
        }
        $c->transcribeModel = $e('TALIXIO_TRANSCRIBE_MODEL', match ($c->transcribe) { 'openai' => 'gpt-4o-transcribe', default => '' });
        $c->meetingTier = $e('TALIXIO_MEETING_TIER', 'standard');
        $c->voiceNoteMaxSeconds = (int)$e('TALIXIO_VOICE_NOTE_MAX_SECONDS', '180');
        $c->claudeBin = $e('TALIXIO_CLAUDE_BIN', 'claude');
        $c->dataDir = rtrim($e('TALIXIO_DATA_DIR', 'data'), '/');
        if (!str_starts_with($c->dataDir, '/')) $c->dataDir = getcwd() . '/' . $c->dataDir; // child processes run elsewhere
        $c->timezone = $e('TALIXIO_TIMEZONE', 'UTC');
        $c->name = $e('TALIXIO_NAME', 'Talixio');
        $c->historyMessages = (int)$e('TALIXIO_HISTORY_MESSAGES', '40');
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
        $c->prices = $e('TALIXIO_PRICES');
        $c->maxParallelJobs = max(1, (int)$e('TALIXIO_MAX_PARALLEL_JOBS', '3'));
        $c->jobMaxUsd = (float)$e('TALIXIO_JOB_MAX_USD', '0.50');
        $c->ollamaUrl = rtrim($e('TALIXIO_OLLAMA_URL', 'http://127.0.0.1:11434'), '/');
        $c->voyageKey = $e('VOYAGE_API_KEY');
        $c->embeddings = strtolower($e('TALIXIO_EMBEDDINGS', 'auto'));
        if ($c->embeddings === 'auto') {
            $c->embeddings = match (true) {
                $c->voyageKey !== '' => 'voyage',
                $c->geminiKey !== '' => 'gemini',
                $c->openaiKey !== '' && str_contains($c->openaiBaseUrl, 'api.openai.com') => 'openai',
                default => 'off',
            };
        }
        $c->embeddingsModel = $e('TALIXIO_EMBEDDINGS_MODEL', match ($c->embeddings) {
            'ollama' => 'nomic-embed-text', 'openai' => 'text-embedding-3-small', 'voyage' => 'voyage-3.5-lite', 'gemini' => 'gemini-embedding-001', default => '',
        });
        $c->recallMinSim = (float)$e('TALIXIO_RECALL_MIN_SIMILARITY', '0.5');
        $list = fn(string $v) => array_values(array_filter(array_map(fn($x) => strtolower(trim($x)), explode(',', $v)), 'strlen'));
        $c->smtpHost = $e('TALIXIO_SMTP_HOST');
        $c->smtpPort = (int)$e('TALIXIO_SMTP_PORT', '587');
        $c->smtpSecurity = strtolower($e('TALIXIO_SMTP_SECURITY', 'auto'));
        $c->smtpUser = $e('TALIXIO_SMTP_USER');
        $c->smtpPassword = $e('TALIXIO_SMTP_PASSWORD');
        $c->emailFrom = $e('TALIXIO_EMAIL_FROM', $c->smtpUser);
        $c->emailTo = $e('TALIXIO_EMAIL_TO');
        $c->emailAllowed = $list($e('TALIXIO_EMAIL_ALLOWED', $c->emailTo));
        $c->emailRequireAuth = $e('TALIXIO_EMAIL_REQUIRE_AUTH', '1') !== '0';
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
        $c->mailboxFrom = $e('TALIXIO_MAILBOX_FROM', $c->mailboxUser);
        $c->mailboxDrafts = $e('TALIXIO_MAILBOX_DRAFTS', 'Drafts');
        $c->mailboxSent = $e('TALIXIO_MAILBOX_SENT', 'Sent');
        $c->autoApprove = $list($e('TALIXIO_AUTO_APPROVE'));
        $c->signalCli = $e('TALIXIO_SIGNAL_CLI', 'signal-cli');
        $c->signalNumber = $e('TALIXIO_SIGNAL_NUMBER');
        $c->signalAllowed = array_values(array_filter(array_map('trim', explode(',', $e('TALIXIO_SIGNAL_ALLOWED'))), 'strlen'));
        $c->notify = $list($e('TALIXIO_NOTIFY', 'ntfy,webpush,telegram,email,signal'));
        date_default_timezone_set($c->timezone);
        return $c;
    }

    public function tier(string $name): Tier { return $this->tiers[$name] ?? $this->tiers['standard']; }
    public function embeddingsBackend(): string { return $this->embeddings; }
    public function filesDir(): string { return $this->dataDir . '/files'; }
    public function dbPath(): string { return $this->dataDir . '/talixio.db'; }
    public function workspaceDir(): string { return $this->dataDir . '/workspace'; }
    /** Models served by a local OpenAI-compatible server cost nothing. */
    public function localModels(): bool { return $this->provider === 'openai' && !str_contains($this->openaiBaseUrl, 'openai.com'); }
    /** Providers that speak the OpenAI chat completions API. */
    public function openaiCompatible(): bool { return in_array($this->provider, ['openai', 'gemini'], true); }
}
