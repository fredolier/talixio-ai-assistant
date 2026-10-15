# Canaux et notifications

## Notifications de l'app
Page **Canaux** → *Activer pour cet appareil* (sur iPhone : d'abord l'ajouter à l'écran d'accueil et
l'ouvrir depuis là). Un appareil à la fois ; aucun service supplémentaire. *Envoyer une notification de test* pour vérifier.

## Lire ses notifications dans l'app
Tout ce que l'assistant envoie (rappels, tâches planifiées, rapports de travaux, validations, alertes) est aussi
gardé dans l'app : **Notifications** dans le menu (sur téléphone : *Plus* → *Notifications*). Un nombre rouge
indique combien sont non lues ; l'icône de l'app les compte aussi, avec les validations en attente. Touchez-en
une pour ouvrir sa conversation et la marquer comme lue, ou *Tout marquer comme lu*. Ouvrir la page les retire
aussi de l'écran verrouillé du téléphone. Les 200 dernières sont gardées, pendant 60 jours. Dans un espace
Secret, la liste dit seulement où regarder.

## Telegram
Créez un bot avec @BotFather, mettez son jeton dans Réglages → Telegram, écrivez au bot : il répond
avec votre identifiant, à mettre dans la liste des identifiants autorisés.

## Signal
Il faut un numéro dédié à l'assistant (une carte prépayée ou une eSIM qui reçoit un SMS).
Réglages → Signal : le numéro de l'assistant et le vôtre, puis l'enregistrement :
- installation simple : `sudo talixio signal register`, puis `sudo talixio signal verify 123456` ;
- sous Docker : `docker exec -it talixio-<vous> talixio signal register`, puis `… verify 123456`, puis `docker restart talixio-<vous>`.
Si un captcha est demandé : https://signalcaptchas.org/registration/generate.html, puis
`register --captcha 'signalcaptcha://…'`.

## E-mail
Écrire à l'assistant par e-mail, et recevoir ses messages : Réglages → E-mail (SMTP, IMAP, expéditeurs autorisés).
Les messages de l'assistant (tâches planifiées, briefing, rapports, réponses) arrivent mis en page : une carte avec le titre,
l'espace et sa couleur, les titres, listes, tableaux et liens du message, et un bouton qui ouvre l'app.
Pour que les liens et le bouton fonctionnent, l'app doit connaître son adresse : l'ouvrir une fois dans le navigateur suffit
(ou Réglages → adresse publique). Les e-mails envoyés en votre nom depuis votre boîte restent sobres, sans ce cadre.

## ntfy
Une autre façon de recevoir les notifications : Réglages → *ntfy*, avec un sujet long et aléatoire.

## Notifications sans contenu
Réglages → *Notifications sans contenu* : « Nouveau message dans … » au lieu du début de la réponse.
C'est automatique dans les espaces Confidentiels et Secrets.

Seuls les expéditeurs autorisés reçoivent une réponse, sur tous les canaux.
