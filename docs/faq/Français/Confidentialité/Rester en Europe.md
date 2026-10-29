# Rester en Europe (offre « Europe only »)

Pour que vos factures, contacts, e-mails et documents ne partent pas chez les géants américains, utilisez un
fournisseur européen, payé à l'usage, sans rien à héberger : **Réglages → Modèle et budget**.

## Mise en route
1. Onglet **Fournisseurs** : sur la carte d'un fournisseur européen (OVHcloud, Mistral, Scaleway, Infomaniak, AKI.IO),
   appuyez sur **Se connecter**, créez une clé chez lui (le lien *Créer un compte*) et collez-la sur la carte. Ses modèles
   apparaissent dans l'onglet Modèles.
2. Onglet **Modèles** : envoyez chaque usage (rapide, standard, approfondi, voix) vers un modèle européen, avec les
   boutons **F / S / A / V**.
3. Activez le **Verrou Europe (RGPD)** si rien ne doit sortir d'Europe.

## Quel fournisseur ?

| Fournisseur | Où | Conversation | Recherche par le sens | Transcription |
|---|---|---|---|---|
| OVHcloud AI Endpoints | France | modèles ouverts, dont ceux de Mistral | bge-m3 | Whisper |
| Mistral AI | France | modèles Mistral | mistral-embed | Voxtral |
| Scaleway | Paris | modèles ouverts | Qwen3-Embedding 8B | Whisper |
| Infomaniak | Suisse | modèles ouverts | BGE Multilingual Gemma2 | Whisper |
| AKI.IO | Allemagne | modèles ouverts (prenez les **TL5**) | bge-m3 | — |

- **OVHcloud** est le choix conseillé : hébergé en France, on paie au nombre de mots traités, et on
  peut l'essayer **sans clé** (limites basses).
- **Mistral** : prenez une **offre payante**. Sur le plan gratuit *Experiment*, Mistral peut utiliser
  vos données pour entraîner ses modèles.
- **AKI.IO** : seuls les modèles étiquetés **TL5** sont traités en Allemagne ; les autres peuvent être servis depuis Singapour. Talixio choisit les TL5 pour vous quand votre clé en liste.
- **Infomaniak** demande en plus l'adresse de votre produit IA (elle contient son numéro).
- Les modèles européens ouverts sont bons pour l'usage courant, un peu en dessous des meilleurs
  modèles américains sur les raisonnements difficiles.

## Ce que vous choisissez, et ce que fait le verrou
Connecter un fournisseur ne fait plus tout basculer d'un coup : vous choisissez où va chaque tâche.
- **la conversation** : par usage, dans l'onglet *Modèles* (on peut mélanger plusieurs fournisseurs) ;
- **la recherche par le sens** : Réglages → *Recherche par le sens* (un modèle sur ce serveur, ou celui du fournisseur
  européen). Changer de modèle réindexe la mémoire en arrière-plan ;
- **la transcription** des vocaux et des réunions : elle reste sur ce serveur si elle y est installée.

Le **verrou Europe** est une protection à part. L'activer dans l'onglet *Modèles* filtre le tableau sur les modèles et
fournisseurs conformes, et **remplace** toute sélection non conforme par le meilleur modèle souverain ou local (les
remplacements sont signalés). Il n'impose pas quel fournisseur répond : vous pouvez verrouiller sur l'Europe sans changer
de modèle principal.

## Moitié prix pour le travail de fond (Mistral)
Avec Mistral, les résumés des anciennes conversations et la réflexion de l'assistant passent par l'**API
batch** de Mistral : moitié prix, la réponse revient en quelques heures au plus (souvent quelques minutes).
Le worker la récupère et termine le travail. *Compacter maintenant* et *Y réfléchir maintenant* répondent
toujours tout de suite. Un batch qui échoue est refait directement ; quand le budget est presque épuisé,
les appels sont aussi directs. Pour le couper : **Mistral: background work at half price** dans les Réglages
(`TALIXIO_MISTRAL_BATCH=0`). Dans Activité, ces appels apparaissent comme *summary (batch)* ou *mind (batch)*,
à la moitié du coût estimé.

## Le verrou
Avec le verrou (**Verrou Europe (RGPD)** de l'onglet *Modèles*, `TALIXIO_SOVEREIGN_ONLY=1`), ce qui n'a pas d'équivalent européen est **coupé** au lieu
de partir ailleurs :
- la recherche web, sauf avec votre propre SearXNG ;
- la recherche par le sens et la transcription chez un service hors d'Europe (on garde la recherche
  par les mots, et la transcription sur ce serveur si elle est installée).

## Un serveur plus petit
Par défaut, ce qui tourne déjà sur votre serveur y reste : la recherche par le sens avec Ollama, et la transcription
locale. Pour libérer de la mémoire, choisissez le modèle d'embeddings du fournisseur européen dans Réglages → *Recherche par le
sens* : la bibliothèque et la mémoire sont réindexées en arrière-plan avec lui, et le serveur n'a plus de modèle à faire
tourner. En multi-utilisateur, décochez ensuite Ollama dans l'administration : c'est ce qui consomme le plus de mémoire.

## Les clés hors d'Europe ne servent-elles vraiment à rien ?
Avec le verrou, chaque requête vers Anthropic, OpenAI, Gemini, Voyage, Brave ou Tavily est **refusée au
moment de partir**, même si une clé est enregistrée. La tentative est notée dans **Confidentialité →
journal** : vous pouvez vérifier qu'elle n'a jamais lieu. Sans verrou, une clé ne sert que si un service
est explicitement réglé sur ce fournisseur.

## Comment savoir que tout reste en Europe
- En haut des **Réglages**, un tampon **★ 100 % Europe · RGPD** s'affiche dès que plus rien ne sort
  d'Europe (survolez-le pour voir le fournisseur et le pays). S'il reste quelque chose, il devient
  orange et indique combien de services sortent encore.
- **Lancer les vérifications** ajoute une étape *UE · RGPD* : elle confirme que tout reste en Europe, chez
  quel fournisseur, et si le verrou est mis ; sinon elle signale en rouge ce qui sort encore.
- Verrou activé, le tableau des modèles masque les modèles et fournisseurs non conformes : leurs clés restent enregistrées mais ne servent pas.

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
  chez le fournisseur ; choisissez un autre modèle avec le bouton **E** dans Réglages → Modèle et budget → Modèles.
