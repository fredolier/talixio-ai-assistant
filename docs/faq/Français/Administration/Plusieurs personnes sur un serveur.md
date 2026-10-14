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

## Bon à savoir
- Chaque assistant est limité (1 Go et 1,5 processeur par défaut) ; Ollama prend jusqu'à 1,5 Go et la moitié des processeurs ; décoché dans les réglages de l'admin, il est arrêté.
- La transcription locale (modèle large) a besoin de 2 Go pour la personne.
- L'administrateur peut techniquement accéder aux volumes de données.
