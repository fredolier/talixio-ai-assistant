<?php
declare(strict_types=1);
namespace Talixio;

/**
 * The few fixed sentences the server writes to the user itself (the assistant's answers are in the user's language
 * already; the web interface is translated in the browser, web/public/i18n.js). Language: TALIXIO_LANG, or with
 * "auto" the language of the browser that last opened the app.
 */
final class I18n
{
    public const LANGS = ['en', 'fr'];

    private const FR = [
        "I've reached my spending limit, so I'm not calling the model right now. {budget}\nRaise TALIXIO_BUDGET_DAILY_USD / TALIXIO_BUDGET_MONTHLY_USD if needed." =>
            "J'ai atteint ma limite de dépenses : je n'appelle pas le modèle pour l'instant. {budget}\nAugmentez le budget dans Réglages → Budget si besoin.",
        "I couldn't answer: {error}" => "Je n'ai pas pu répondre : {error}",
        '(no response)' => '(pas de réponse)',
        '*(stopped)*' => '*(arrêté)*',
        'Got the recording{mins}. I\'m transcribing and summarising it in the background (job #{id}): you\'ll get the summary, decisions and action items here.' =>
            "Enregistrement reçu{mins}. Je le transcris et le résume en arrière-plan (job #{id}) : vous aurez ici le résumé, les décisions et les actions à mener.",
        'Spaces: {list}. Switch with "/space name".' => 'Espaces : {list}. Pour changer : « /espace nom ».',
        ' (current)' => ' (actuel)',
        'No space called "{name}". Spaces: {list}.' => 'Aucun espace « {name} ». Espaces : {list}.',
        'Now in the space "{name}". "/space general" to come back.' => 'Vous êtes dans l\'espace « {name} ». « /espace general » pour revenir.',
        "There's no approval request #{id}." => "Il n'y a pas de demande de validation n° {id}.",
        'Request #{id} was already {status}.' => 'La demande n° {id} a déjà été traitée ({status}).',
        'Request #{id} was already handled.' => 'La demande n° {id} a déjà été traitée.',
        'Cancelled: {what}' => 'Annulé : {what}',
        'Approved: {what}. Continuing.' => 'Validé : {what}. Je continue.',
        'Done: {what}' => 'Fait : {what}',
        'Failed: {what}' => 'Échec : {what}',
        'Learned a skill: **{name}**: {description}' . "\n" . 'You can see or edit it on the Memory page.' =>
            'Nouveau savoir-faire : **{name}** : {description}' . "\n" . 'Vous pouvez le voir ou le modifier dans la page Mémoire.',
        'Updated a skill: **{name}**: {description}' . "\n" . 'You can see or edit it on the Memory page.' =>
            'Savoir-faire mis à jour : **{name}** : {description}' . "\n" . 'Vous pouvez le voir ou le modifier dans la page Mémoire.',
    ];

    public static function lang(App $app): string
    {
        if ($app->cfg->lang !== 'auto') return $app->cfg->lang;
        $l = $app->store->get('ui_lang');
        return in_array($l, self::LANGS, true) ? $l : 'en';
    }

    /** The language of the browser that opens the app (when the interface language is automatic). */
    public static function remember(App $app, string $acceptLanguage): void
    {
        $l = 'en';
        foreach (explode(',', strtolower($acceptLanguage)) as $part) {
            $k = substr(trim($part), 0, 2);
            if (in_array($k, self::LANGS, true)) { $l = $k; break; }
        }
        if ($app->store->get('ui_lang') !== $l) $app->store->set('ui_lang', $l);
    }

    public static function t(App $app, string $text, array $vars = []): string
    {
        $out = self::lang($app) === 'fr' ? (self::FR[$text] ?? $text) : $text;
        foreach ($vars as $k => $v) $out = str_replace('{' . $k . '}', (string)$v, $out);
        return $out;
    }
}
