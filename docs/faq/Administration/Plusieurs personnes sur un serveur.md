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
  défaut, Ollama, client Google), **Sécurité** (adresses autorisées, mot de passe), **Activité** (journal).

## Adresses
`https://<domaine>/<personne>/` par défaut, ou `https://<personne>.<domaine>/` (il faut alors un DNS en joker).

## Bon à savoir
- Chaque assistant est limité (1 Go et 1,5 processeur par défaut) ; Ollama prend 1,5 Go.
- La transcription locale (modèle large) a besoin de 2 Go pour la personne.
- L'administrateur peut techniquement accéder aux volumes de données.
