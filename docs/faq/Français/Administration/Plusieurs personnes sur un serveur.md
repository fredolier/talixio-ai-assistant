# Plusieurs personnes sur un serveur (Docker)

Un assistant par personne, chacun dans son conteneur, avec ses données, ses clés (et ses coûts) et son
mot de passe ; un Ollama partagé pour la recherche par le sens ; une page d'administration sans SSH.

## Installer
```bash
sudo deploy/docker/install-stack.sh --domain assistant.example.com --email vous@example.com
```
Le script installe Docker, construit l'image (plusieurs minutes, ~2,5 Go), démarre Caddy (HTTPS),
Ollama, l'agent et la page d'administration, puis affiche un **code de mise en route**.

## La page d'administration (`https://<domaine>/admin/`)
- Première visite : le code, puis votre mot de passe administrateur et l'application d'authentification.
- **Personnes** : *+ Nouvelle personne* (l'adresse et un premier mot de passe à transmettre), démarrer,
  arrêter, redémarrer, vérifier, sauvegarder, limites (mémoire, processeurs), nouveau mot de passe,
  réinitialiser la connexion en deux étapes, retirer (avec ou sans ses données).
- *Depuis un autre serveur* : faire venir un assistant existant (voir *Transférer un assistant*).
- **Mises à jour**, **Réglages** (domaine, adresses en /personne ou en sous-domaines, limites par
  défaut, Ollama, client Google, fournisseur européen commun), **Sécurité** (adresses autorisées, mot de passe), **Activité** (journal).

## Le serveur est-il bien dimensionné ?
En haut de l'onglet Personnes, le panneau **Serveur** montre :
- la mémoire, les processeurs (charge sur 1, 5 et 15 minutes) et le disque (images, données, cache de construction) ;
- les **services communs** (Caddy, Ollama, agent, administration) : s'ils répondent, en combien de temps,
  leurs redémarrages et leur mémoire ;
- un **diagnostic** (Bien dimensionné, Juste, Sous-dimensionné). Il compare les limites promises
  (personnes, services communs et système) à la mémoire réelle, et vérifie la charge des processeurs,
  le swap et le disque libre. Il indique aussi combien de personnes on peut encore ajouter.

Pour chaque personne : si son assistant répond, le temps de son dernier contrôle, ses redémarrages et
s'il a été arrêté par manque de mémoire. Dans ce cas, augmentez sa limite (Actions → Mémoire et processeurs).

## Adresses
`https://<domaine>/<personne>/` par défaut, ou `https://<personne>.<domaine>/` (il faut alors un DNS en joker).
Avec une seule adresse, tous les assistants et la page d'administration partagent la même origine web : le
navigateur ne les sépare pas, et quelqu'un qui peut exécuter du code dans son propre assistant pourrait
atteindre la session d'une autre personne. Choisissez les **sous-domaines** sauf si tout le monde sur le
serveur se fait confiance. Sur un serveur partagé, les connecteurs locaux tournent toujours dans le bac à sable.

## Plusieurs assistants pour une personne, une seule connexion
Une personne peut avoir plusieurs assistants : un pour le travail et un privé, par exemple, chacun
avec ses données, ses clés et son fournisseur. Talixio a alors deux niveaux : l'**assistant** (un
conteneur à part, rien de partagé) et, dedans, ses **espaces** (une mémoire et une bibliothèque,
avec les règles de leur niveau de confidentialité).
- Sur la page d'administration : l'assistant de la personne → Actions → *Ses autres assistants
  (une seule connexion)…*, puis cochez les assistants qu'il ouvre. Un lien vaut dans les deux sens,
  et les assistants concernés redémarrent. Les liens se suivent aussi : reliez *Travail* et *PIA* à
  *Privé*, et *Travail* ouvre aussi *PIA* ; chaque assistant liste donc tous les autres de la personne.
- Dans l'application, le nom de l'assistant (en haut à gauche, ou dans *Plus* sur un téléphone)
  liste les autres. En choisir un l'ouvre dans le même onglet, déjà connecté. Menu replié, la
  pastille ronde en haut ouvre la même liste.
- Dans l'application installée, changer d'assistant reste dans la fenêtre de l'application quand
  les assistants partagent une adresse (`assistant.example.com/alex-travail/`, `…/alex-perso/`). Si une
  barre avec l'adresse et une ✕ apparaît encore, fermez l'application et rouvrez-la une fois pour
  qu'elle prenne le changement. Avec une adresse par assistant (`alex-travail.example.com`), le
  navigateur montre toujours cette barre : installez chacun.
- Chaque assistant a une pastille colorée avec ses initiales, tirées de son identifiant (`alex-acme`
  → AA) : mêmes couleur et lettres sur tous les assistants, pour voir d'un coup d'œil où vous êtes.
  La liste montre le nom et l'identifiant de chaque assistant, l'actuel en premier.
- Les noms de cette liste viennent de la page d'administration, et sont aussi le nom propre de
  chaque assistant (dans les e-mails et les notifications) : son *Nom* dans les Réglages l'affiche, en
  lecture seule. Pour en changer un, choisissez **Nom et e-mail…** dans son menu *Actions* sur la
  page d'administration (`Alex — Travail`, `Alex — Perso`) : cet assistant et ceux qui lui sont liés
  redémarrent, quelques secondes chacun, pour afficher le nouveau nom.
- Le navigateur transporte un ticket signé pour cet assistant seulement, valable une fois et une
  minute. Les assistants ne se parlent jamais.
- La connexion au premier assistant compte : le second ne redemande ni son mot de passe ni son
  code d'authentification. Chaque assistant garde son propre mot de passe, qui marche toujours seul.
- Retirer un lien change les clés des assistants concernés : les tickets faits avant ne marchent plus.

## Bon à savoir
- Chaque assistant est limité (1 Go et 1,5 processeur par défaut) ; Ollama prend jusqu'à 1,5 Go et la moitié des processeurs ; décoché dans les réglages de l'admin, il est arrêté.
- La transcription locale (modèle large) a besoin de 2 Go pour la personne.
- L'administrateur peut techniquement accéder aux volumes de données.
