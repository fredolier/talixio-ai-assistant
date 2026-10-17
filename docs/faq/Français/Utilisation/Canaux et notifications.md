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

## Boîtes Outlook / Microsoft 365
Microsoft supprime les mots de passe pour SMTP et IMAP (smtp.office365.com, outlook.office365.com) ; beaucoup
d'organisations les refusent déjà (« 535 5.7.3 Authentication unsuccessful » ou « 5.7.30 »). Connectez-vous
plutôt avec le compte Microsoft :
1. Configurez l'application Microsoft sous Réglages → *Calendrier* → *Outlook / Microsoft 365* (étapes 1 à 5),
   et ajoutez les autorisations déléguées *SMTP.Send* et *IMAP.AccessAsUser.All*.
2. Dans les réglages e-mail (ceux de l'assistant, ou de votre boîte), *Connexion* → *Compte Microsoft 365 (OAuth)* : les serveurs
   passent à ceux de Microsoft (smtp.office365.com, outlook.office365.com) ; enregistrez. Le jeton du compte n'est
   envoyé qu'aux serveurs de Microsoft.
3. Appuyez sur *Se connecter avec Microsoft* et connectez-vous avec le compte de cette boîte. Laissez le mot de passe vide.
   De retour dans les Réglages, vous lisez *Connecté en tant que …*. Si le bouton est toujours là, la raison
   de l'échec s'affiche dessous (secret de l'application faux ou expiré, autorisation non accordée…).
L'adresse de l'assistant, votre boîte et le calendrier ont chacun leur propre connexion.
Dans le centre d'administration Microsoft 365, *SMTP authentifié* doit rester activé pour la boîte
(Utilisateurs → l'utilisateur → Courrier → Gérer les applications de messagerie), même avec cette connexion.

## ntfy
Une autre façon de recevoir les notifications : Réglages → *ntfy*, avec un sujet long et aléatoire.

## Notifications sans contenu
Réglages → *Notifications sans contenu* : « Nouveau message dans … » au lieu du début de la réponse.
C'est automatique dans les espaces Confidentiels et Secrets.

Seuls les expéditeurs autorisés reçoivent une réponse, sur tous les canaux.
