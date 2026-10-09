<?php
declare(strict_types=1);
namespace Talixio;

/**
 * Buttons that open an app on the user's phone (Android): a web page can't start an app by itself,
 * but a link the user taps can. The assistant puts the link in its answer; the app shows it as a button.
 */
final class PhoneLinks
{
    /** Common apps: Android package, and a link that opens them (their own address or one they answer to). */
    public const APPS = [
        'spotify' => ['com.spotify.music', 'spotify:'],
        'youtube' => ['com.google.android.youtube', 'https://www.youtube.com/'],
        'youtube music' => ['com.google.android.apps.youtube.music', 'https://music.youtube.com/'],
        'deezer' => ['deezer.android.app', 'deezer://www.deezer.com/'],
        'netflix' => ['com.netflix.mediaclient', 'https://www.netflix.com/'],
        'google maps' => ['com.google.android.apps.maps', 'https://www.google.com/maps'],
        'maps' => ['com.google.android.apps.maps', 'https://www.google.com/maps'],
        'waze' => ['com.waze', 'https://waze.com/ul'],
        'whatsapp' => ['com.whatsapp', 'https://wa.me/'],
        'telegram' => ['org.telegram.messenger', 'tg://resolve'],
        'signal' => ['org.thoughtcrime.securesms', 'sgnl://'],
        'instagram' => ['com.instagram.android', 'https://www.instagram.com/'],
        'uber' => ['com.ubercab', 'uber://'],
        'gmail' => ['com.google.android.gm', 'mailto:'],
        'home assistant' => ['io.homeassistant.companion.android', 'homeassistant://navigate/lovelace/0'],
    ];

    /** Schemes the app shows as buttons (the chat renderer allows the same list). */
    public const SCHEMES = ['tel', 'sms', 'mailto', 'geo', 'spotify', 'intent', 'deezer', 'tg', 'sgnl', 'uber', 'homeassistant'];

    private static function link(string $label, string $href): string { return '[▶ ' . str_replace([']', '['], '', $label) . "]($href)"; }

    private static function phone(string $n): string { return preg_replace('/[^\d+]/', '', $n); }

    /**
     * kind: app | music | navigate | call | sms | whatsapp | email | video | web
     * target: app name/package, search, address, phone number, email address or URL. text: message body (sms, whatsapp, email).
     */
    public static function make(string $kind, string $target, string $text = '', string $via = ''): string
    {
        $target = trim($target); $q = rawurlencode($target); $t = rawurlencode($text);
        if ($target === '' && !in_array($kind, ['music'], true)) throw new \InvalidArgumentException('target is needed');
        switch ($kind) {
            case 'app':
                $key = mb_strtolower($target);
                if (isset(self::APPS[$key])) return self::link('Open ' . ucwords($key), self::APPS[$key][1]);
                if (preg_match('/^[a-z][\w]*(\.[a-z][\w]*)+$/i', $target)) // an Android package: Chrome opens it, or its Play Store page (with an "Open" button)
                    return self::link("Open $target", "intent://#Intent;package=$target;S.browser_fallback_url=" . rawurlencode("https://play.google.com/store/apps/details?id=$target") . ';end');
                return self::link("Find $target", "https://play.google.com/store/search?q=$q&c=apps");
            case 'music':
                return match (mb_strtolower($via)) {
                    'youtube music', 'youtube' => self::link("Play $target on YouTube Music", "https://music.youtube.com/search?q=$q"),
                    'deezer' => self::link("Play $target on Deezer", "deezer://www.deezer.com/search/$q"),
                    default => self::link($target !== '' ? "Play $target on Spotify" : 'Open Spotify', $target !== '' ? "spotify:search:$q" : 'spotify:'),
                };
            case 'navigate':
                return mb_strtolower($via) === 'waze'
                    ? self::link("Go to $target with Waze", "https://waze.com/ul?q=$q&navigate=yes")
                    : self::link("Go to $target", "https://www.google.com/maps/dir/?api=1&destination=$q");
            case 'call': return self::link("Call $target", 'tel:' . self::phone($target));
            case 'sms': return self::link("Text $target", 'sms:' . self::phone($target) . ($text !== '' ? "?body=$t" : ''));
            case 'whatsapp': return self::link("WhatsApp $target", 'https://wa.me/' . ltrim(self::phone($target), '+') . ($text !== '' ? "?text=$t" : ''));
            case 'email': return self::link("Write to $target", 'mailto:' . str_replace('%40', '@', rawurlencode($target)) . ($text !== '' ? "?body=$t" : ''));
            case 'video': return self::link("Watch \"$target\" on YouTube", "https://www.youtube.com/results?search_query=$q");
            case 'web':
                if (!preg_match('#^https?://#i', $target)) throw new \InvalidArgumentException('target must be an http(s) address');
                return self::link('Open ' . (parse_url($target, PHP_URL_HOST) ?: $target), str_replace([' ', ')', '('], ['%20', '%29', '%28'], $target));
        }
        throw new \InvalidArgumentException("unknown kind $kind");
    }
}
