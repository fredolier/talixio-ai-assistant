# Transférer un assistant vers un autre serveur

Tout part : conversations, mémoire, compétences, bibliothèque, tâches, réglages et clés, mot de passe,
connexion en deux étapes, enregistrement Signal.

## 1. Sur l'ancien serveur, dans l'assistant
Réglages → **Déménager vers un autre serveur** → *Créer un code de transfert*. Notez l'adresse de
l'assistant et le code (`tx-…`) : il n'est affiché qu'une fois et vaut 24 heures et **une copie**
(un nouveau code remplace l'ancien ; *Annuler* le désactive). L'ancien serveur doit être en version 0.15 ou plus.

## 2. Sur le nouveau serveur
- **Docker** : page d'administration → Personnes → **Depuis un autre serveur** : l'adresse, le code,
  l'identifiant ici (ex. `fred`) et le nom. Le suivi s'affiche dans l'onglet Mises à jour.
- **Installation simple** : `sudo talixio transfer-pull https://ancien.example.com/fred tx-… --url https://nouveau.example.com`
  (l'app est arrêtée pendant la copie, puis redémarrée).

## Copie d'essai, puis copie finale
- Une **copie d'essai** arrive **en pause** : pas de Telegram, Signal, e-mail, routines ni rappels, pour que
  les deux assistants ne répondent jamais aux mêmes messages. Vous pouvez l'ouvrir et vérifier. Refaites
  une copie autant de fois que nécessaire, avec un nouveau code à chaque fois : elle remplace la précédente.
- La **copie finale** (un nouveau code, case *Déménagement définitif*, ou `--final`) : le nouvel assistant est actif, et
  l'ancien se met **en pause** et affiche la nouvelle adresse.
- Une copie d'essai peut aussi devenir l'assistant avec *Utiliser celui-ci* (mettez d'abord l'ancien en pause).

## Ce qui reste à faire après
- Ouvrir la nouvelle adresse et réinstaller l'app sur le téléphone ; réactiver les notifications.
- Les liens de partage déjà envoyés utilisent l'ancienne adresse.
- Google Agenda et les connecteurs OAuth : ajoutez la nouvelle adresse de retour chez le fournisseur si besoin.
- Les données qui étaient avant dans l'assistant de destination sont gardées dans `.previous-<date>` (la dernière seulement).

## Sécurité
Le code donne accès à toutes les données : ne le transmettez que par un canal sûr. La copie passe
**uniquement en https**. Sur un réseau local sans certificat, l'installation simple accepte `http://` vers une
adresse privée avec `--allow-http` ; une adresse publique est toujours refusée en http. Après 5 codes faux,
l'adresse qui essaie est bloquée un moment, et vous êtes prévenu à chaque copie.
