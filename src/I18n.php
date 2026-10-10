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
        // shared links: the page people outside see (in their browser's language), and the notification
        'Link not available' => 'Lien indisponible',
        'This link has expired or was removed. Ask the person who sent it for a new one.' => "Ce lien a expiré ou a été supprimé. Demandez-en un nouveau à la personne qui vous l'a envoyé.",
        'Wrong password.' => 'Mot de passe incorrect.',
        'Too many wrong passwords. Try again in 15 minutes.' => 'Trop de mots de passe incorrects. Réessayez dans 15 minutes.',
        'Protected documents' => 'Documents protégés',
        'This link is protected. Password:' => 'Ce lien est protégé. Mot de passe :',
        'Open' => 'Ouvrir',
        'Not allowed' => 'Non autorisé',
        'This link is for viewing only.' => 'Ce lien permet seulement de consulter.',
        'Not found' => 'Introuvable',
        'This document is not part of the link.' => 'Ce document ne fait pas partie du lien.',
        'This kind of document can only be downloaded, and this link is for viewing only.' => 'Ce type de document peut seulement être téléchargé, et ce lien permet seulement de consulter.',
        'View' => 'Voir',
        'Download' => 'Télécharger',
        'Document' => 'Document',
        'Available until {date}.' => "Disponible jusqu'au {date}.",
        'Shared with you.' => 'Partagé avec vous.',
        'View only.' => 'Consultation seulement.',
        'Download everything (zip)' => 'Tout télécharger (zip)',
        'This folder is empty.' => 'Ce dossier est vide.',
        'Shared with Talixio.' => 'Partagé avec Talixio.',
        'Shared link opened' => 'Lien partagé ouvert',
        '"{name}"{who} was opened for the first time.' => '« {name} »{who} a été ouvert pour la première fois.',
        ' (for {label})' => ' (pour {label})',
        // confidentiality
        'New message in {space}' => 'Nouveau message dans {space}',
        '"{name}" is a Confidential space: Telegram can read the messages, so use it in the app (or Signal).' => '« {name} » est un espace Confidentiel : Telegram peut lire les messages, utilisez-le dans l\'app (ou Signal).',
        'This is a Secret space: nothing may leave the server, and the assistant runs on a service outside. The library and tasks of this space still work; to chat here, use a model on this server (Settings → AI model).' =>
            'Cet espace est Secret : rien ne doit quitter le serveur, et l\'assistant fonctionne avec un service extérieur. La bibliothèque et les tâches de cet espace fonctionnent toujours ; pour discuter ici, utilisez un modèle installé sur ce serveur (Réglages → Modèle d\'IA).',
        'This is a Confidential space: only a model under business terms (Claude API, OpenAI API, paid Gemini) may read its content, and the current one is not. Change the model in Settings → AI model, or the level of the space.' =>
            'Cet espace est Confidentiel : seul un modèle sous contrat professionnel (API Claude, API OpenAI, Gemini payant) peut en lire le contenu, ce qui n\'est pas le cas du modèle actuel. Changez de modèle dans Réglages → Modèle d\'IA, ou le niveau de l\'espace.',
        // moving to another server
        'Transfer' => 'Transfert',
        'Your assistant was moved to another server ({to}). This one is paused: it no longer answers or runs routines.' =>
            'Votre assistant a déménagé sur un autre serveur ({to}). Celui-ci est en pause : il ne répond plus et ne lance plus de routines.',
        'A copy of your assistant was taken for another server, from {ip}.' => 'Une copie de votre assistant a été prise pour un autre serveur, depuis {ip}.',
    ];

    /** The language of someone outside (a shared link), from their browser. */
    public static function visitor(string $acceptLanguage): string
    {
        foreach (explode(',', strtolower($acceptLanguage)) as $part) {
            $k = substr(trim($part), 0, 2);
            if (in_array($k, self::LANGS, true)) return $k;
        }
        return 'en';
    }

    public static function tl(string $lang, string $text, array $vars = []): string
    {
        $out = $lang === 'fr' ? (self::FR[$text] ?? $text) : $text;
        foreach ($vars as $k => $v) $out = str_replace('{' . $k . '}', (string)$v, $out);
        return $out;
    }

    public static function lang(App $app): string
    {
        if ($app->cfg->lang !== 'auto') return $app->cfg->lang;
        $l = $app->store->get('ui_lang');
        return in_array($l, self::LANGS, true) ? $l : 'en';
    }

    /** The language of the browser that opens the app (when the interface language is automatic). */
    public static function remember(App $app, string $acceptLanguage): void
    {
        $l = self::visitor($acceptLanguage);
        if ($app->store->get('ui_lang') !== $l) $app->store->set('ui_lang', $l);
    }

    public static function t(App $app, string $text, array $vars = []): string { return self::tl(self::lang($app), $text, $vars); }
}
