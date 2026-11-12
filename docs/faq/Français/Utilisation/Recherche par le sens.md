# La recherche par le sens

La recherche par **les mots** marche toujours. La recherche par **le sens** retrouve aussi un document qui ne
contient pas vos mots (« assurance habitation » trouve « contrat multirisque »). Il faut pour cela transformer
chaque passage en vecteur : le modèle qui le fait se choisit dans **Réglages → Modèle et budget → Modèles**, avec le bouton **E** d'un modèle
d'embeddings (la carte *Embeddings*). **Réglages → Bibliothèque et mémoire → Recherche et mémoire** montre le modèle utilisé et l'index.

| Choix du modèle | Où ça calcule | Mémoire | Remarques |
|---|---|---|---|
| Sur ce serveur : très léger | Ollama, multilingual-e5-small | ≈ 0,3 Go | 5 fois plus léger que « qualité », un peu moins précis |
| Sur ce serveur : léger | Ollama, EmbeddingGemma | ≈ 0,7 Go | 2 fois plus léger, presque aussi bon |
| Sur ce serveur : meilleure qualité | Ollama, Qwen3-Embedding 0.6B | ≈ 1,5 Go | le plus précis ; veut un processeur récent (AVX2) |
| Google Gemini, OpenAI, Voyage | en ligne | rien ici | il faut la clé ; Gemini gratuit peut utiliser les textes |
| Fournisseur européen | en ligne, en Europe | rien ici | la clé du fournisseur européen (voir *Rester en Europe*) |
| Désactivée | — | — | recherche par les mots seulement (*Désactiver la recherche par le sens* sur la carte *Embeddings*) |

- **Changer de choix réindexe tout** en arrière-plan (les vecteurs dépendent du modèle). Pendant ce temps,
  la recherche par les mots reste disponible ; l'avancement s'affiche sous les choix.
- **Mesurer sur ce serveur** donne le temps d'une recherche, d'une indexation (par passage) et de la
  comparaison avec tous les vecteurs : de quoi décider sur des chiffres.
- Les espaces **Secrets** ne sont indexés que par un modèle sur ce serveur, les **Confidentiels** aussi par
  le fournisseur européen. Avec un autre service en ligne, on y cherche par les mots.
- **sqlite-vec** (dans l'image Docker, et installé par `install.sh`) compare les vecteurs dans SQLite,
  environ 6 fois plus vite qu'en PHP (20 000 passages : 0,15 s au lieu de 0,95 s sur une machine de test).
  Sans lui, la comparaison se fait en PHP, plus lentement mais sans rien perdre.

## Plusieurs personnes (Docker)
- L'Ollama est **partagé**. Page d'administration → Réglages → *Recherche partagée* : le modèle par défaut
  et le bouton *Télécharger le modèle*. Chaque personne peut choisir un autre des modèles téléchargés.
- Ollama est limité à la moitié des processeurs (`OLLAMA_CPUS` dans le `.env` de la pile), pour qu'une
  indexation ne prenne pas toute la machine.
- **Décocher** l'Ollama partagé l'arrête : sa mémoire (1,5 Go) revient aux assistants, et le calcul de
  dimensionnement en tient compte. Chacun peut alors utiliser un service en ligne.

## Serveur modeste
Sur un petit processeur, l'indexation d'une grande bibliothèque peut occuper la machine pendant des heures.
Prenez *léger* ou *très léger*, ou un service en ligne si les espaces confidentiels peuvent se contenter des mots.

## Graphe de connaissances (optionnel)
Réglages → *Apprentissage* → *Graphe de connaissances de la bibliothèque* (désactivé par défaut). Activé,
l'assistant note en arrière-plan les personnes, sociétés, projets et lieux cités par chaque document.
Il peut alors rassembler **tout ce qui concerne un nom** (« tout sur Dupont ») ou **ce qui est lié à un
fichier**, en plus de la recherche par les mots et par le sens.
- Cela coûte un appel au modèle rapide par document nouveau ou modifié. *Documents ajoutés au graphe par
  jour* le plafonne (100 par défaut) ; le reste attend les jours suivants.
- Un dossier que le modèle n'a pas le droit de lire (un espace Secret sans modèle local, par exemple)
  n'est jamais envoyé, et l'assistant ne voit que les liens vers des documents qu'il peut lire.
