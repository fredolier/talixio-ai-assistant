# Rester en Europe (offre « Europe only »)

Pour que vos factures, contacts, e-mails et documents ne partent pas chez les géants américains :
**Réglages → Modèle et budget → Europe only**. Un seul fournisseur européen, payé à l'usage, sans rien à
héberger, fait alors tout le travail d'IA.

## Mise en route
1. Choisissez le fournisseur (OVHcloud par défaut).
2. Créez une clé chez lui (le lien est sous le champ) et collez-la.
3. **Test** : il vérifie la clé et montre les modèles retenus pour chaque niveau (rapide, standard,
   approfondi), et si la transcription et la recherche par le sens sont disponibles.
4. Cochez **le verrou** si rien ne doit sortir d'Europe.
5. **Utiliser ce fournisseur**.

## Quel fournisseur ?

| Fournisseur | Où | Conversation | Recherche par le sens | Transcription |
|---|---|---|---|---|
| OVHcloud AI Endpoints | France | modèles ouverts, dont ceux de Mistral | bge-m3 | Whisper |
| Mistral AI | France | modèles Mistral | mistral-embed | Voxtral |
| Scaleway | Paris | modèles ouverts | Qwen3-Embedding 8B | Whisper |
| Infomaniak | Suisse | modèles ouverts | BGE Multilingual Gemma2 | Whisper |

- **OVHcloud** est le choix conseillé : hébergé en France, on paie au nombre de mots traités, et on
  peut l'essayer **sans clé** (limites basses).
- **Mistral** : prenez une **offre payante**. Sur le plan gratuit *Experiment*, Mistral peut utiliser
  vos données pour entraîner ses modèles.
- **Infomaniak** demande en plus l'adresse de votre produit IA (elle contient son numéro).
- Les modèles européens ouverts sont bons pour l'usage courant, un peu en dessous des meilleurs
  modèles américains sur les raisonnements difficiles.

## Ce qui bascule
Un clic sur *Utiliser ce fournisseur* règle :
- **la conversation** (les trois niveaux, choisis automatiquement parmi les modèles du fournisseur) ;
- **la transcription** des vocaux et des réunions (sauf si elle est déjà sur ce serveur) ;
- **la recherche par le sens** (sauf si elle est déjà sur ce serveur, avec Ollama). Changer de modèle
  réindexe la mémoire en arrière-plan.

## Le verrou
Avec le verrou (`TALIXIO_SOVEREIGN_ONLY=1`), ce qui n'a pas d'équivalent européen est **coupé** au lieu
de partir ailleurs :
- la recherche web, sauf avec votre propre SearXNG ;
- la recherche par le sens et la transcription chez un service hors d'Europe (on garde la recherche
  par les mots, et la transcription sur ce serveur si elle est installée).

## Ce qui sort encore d'Europe
La carte en dresse la liste, avec un lien pour changer chaque point. Les cas habituels :

| Service | Pourquoi | Remplacement européen |
|---|---|---|
| Google Agenda | vos rendez-vous sont chez Google | un agenda CalDAV ou iCal (Infomaniak, OVH, Nextcloud) |
| Recherche web | les questions partent chez un moteur américain | SearXNG sur votre serveur, ou désactivée |
| Telegram | les messages passent par Telegram | notifications par e-mail, ou sans contenu |
| Spotify | ce que vous écoutez | — (à déconnecter si besoin) |
| Connecteurs distants | ce que l'assistant leur envoie | un connecteur qui tourne sur ce serveur |

## Espaces confidentiels
- **Confidentiel** : le fournisseur européen est accepté, pour répondre comme pour indexer.
- **Secret** : rien ne sort du serveur, même vers l'Europe ; la recherche s'y fait par les mots
  (ou avec Ollama sur ce serveur).

## RGPD
Être hébergé en Europe ne suffit pas : dans votre espace client chez le fournisseur, **acceptez
l'accord de traitement des données (DPA)**. C'est lui qui engage le fournisseur (sous-traitant au sens
du RGPD, pas d'entraînement sur vos données, lieu de traitement). Notez-le dans le registre des
traitements (page Confidentialité). Les prix affichés pour ces modèles sont approximatifs : vérifiez la
grille du fournisseur.

## Plusieurs personnes sur un serveur
Page d'administration → Réglages → **AI in Europe for everyone** : un compte européen commun à tous
les assistants (fournisseur, clé, adresse, verrou). La clé n'est jamais réaffichée. Les assistants sont
recréés pour la prendre en compte.

## En cas de problème
- *The provider refused the key* : clé erronée ou expirée, ou pas pour ce fournisseur.
- *Could not reach the provider* : le serveur n'atteint pas Internet (pare-feu, proxy).
- Le test dit que la recherche par le sens n'est pas disponible : le nom du modèle a peut-être changé
  chez le fournisseur ; réglez-le dans `TALIXIO_EMBEDDINGS_MODEL`.
