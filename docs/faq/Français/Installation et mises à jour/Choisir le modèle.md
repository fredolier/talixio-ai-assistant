# Choisir le modèle (le « cerveau »)

## Le plus simple : se connecter avec Claude
Avec un abonnement Claude Pro ou Max : Réglages → *Modèle et budget* → **Se connecter avec Claude**
(ou le bouton affiché dans la conversation tant qu'aucun modèle n'est réglé).
1. Ouvrez la page de connexion de Claude et acceptez.
2. Claude affiche un code : copiez-le et collez-le dans Talixio, puis *Connecter*.
C'est la connexion officielle de Claude Code (installé sur le serveur, et inclus dans l'image Docker) ;
Talixio ne voit jamais votre mot de passe. Le jeton obtenu vaut un an.

L'utilisation compte dans les limites de votre abonnement. Les conditions d'Anthropic sur l'usage d'un
abonnement par des outils comme celui-ci ont changé plusieurs fois en 2026 : vérifiez-les.
Pour un usage professionnel, une **clé d'API** (facturée à l'usage, sous contrat pro) reste la voie la plus sûre.

## Les autres choix
Réglages → *Modèle et budget* → *Fournisseurs* (ou un modèle réglé dans les réglages principaux) :

| Option | Réglage |
|---|---|
| API Claude | fournisseur `anthropic`, clé `ANTHROPIC_API_KEY` |
| Abonnement Claude Pro/Max | `--with-claude-code`, puis `claude setup-token` ; fournisseur `claude-code` |
| API Gemini | fournisseur `gemini`, clé `GEMINI_API_KEY` (https://aistudio.google.com/apikey) |
| OpenAI | fournisseur `openai`, clé `OPENAI_API_KEY` |
| Fournisseur européen (OVHcloud, Mistral, Scaleway, Infomaniak, AKI.IO) | à connecter sur sa carte de l'onglet *Fournisseurs* (voir *Rester en Europe*) ; fournisseur `sovereign` |
| Modèle local (Ollama…) | fournisseur `openai`, adresse `http://localhost:11434/v1`, nom du modèle |

## Un fournisseur différent par espace (comptes d'IA)
Réglages → *Modèle et budget* → *Fournisseurs* : connecter un fournisseur sur sa carte crée un compte d'IA (un nom, un fournisseur et sa clé ou son jeton) ;
*+ Ajouter un fournisseur sur mesure* ouvre le formulaire de compte complet pour les cas moins courants.
Choisissez ensuite le compte dans les réglages de l'espace (*Compte d'IA*) ; pour Général, dans le menu des espaces.
Les espaces sans compte utilisent les réglages principaux.

- **Deux abonnements Claude** (par ex. « Pro » et « Provite ») : sur un ordinateur connecté à chaque abonnement, lancez
  `claude setup-token` et collez le jeton dans un compte de type *Abonnement Claude*.
- **Plusieurs fournisseurs européens** : un compte par fournisseur (Mistral pour un client, OVHcloud pour un autre…).
  Un espace sur un compte européen peut recevoir des conversations *confidentielles* même si le modèle principal est un abonnement.
- Chaque compte a ses propres limites chez le fournisseur : la charge se répartit entre eux.
- *Tester* vérifie le compte avant de l'enregistrer. Les clés et jetons ne quittent jamais le serveur ; laissez le champ vide pour garder celui enregistré.
- La transcription et la recherche par le sens gardent les réglages principaux.
- Supprimer un compte remet ses espaces sur les réglages principaux.

## La page Modèle et budget
Réglages → *Modèle et budget* a trois onglets.

- **Modèles** : un catalogue de tous les fournisseurs et modèles, que l'on trie et filtre (recherche, type, fournisseur,
  niveau de confiance, disponibilité). Chaque ligne indique son **rang** (1 basique → 10 haut de gamme), son **prix**
  (entrée / sortie par million de jetons, par minute pour la voix, par image pour les images ; un « ~ » signale une
  estimation), son **type** (LLM, vision, voix, texte → image, **embedding**) et son **niveau de confiance** de 1 (grand
  public) à 5 (souverain, RGPD) ; *loc* désigne un modèle sur votre propre machine.
- **Fournisseurs** : une carte par fournisseur avec son état, son pays, sa facturation et sa confiance. **Se connecter** sur
  la carte, collez la clé sur place (le lien *Créer un compte* ouvre l'inscription du fournisseur), et ses modèles
  apparaissent aussitôt dans l'onglet Modèles. Les fournisseurs locaux, DeepSeek, Hugging Face et ElevenLabs passent par la
  carte *+ Ajouter un fournisseur sur mesure*.
- **Budget** : les limites (voir plus bas) et ce qui a été dépensé ce mois-ci.

### Chaque usage sur son propre modèle
Les cartes au-dessus du tableau montrent ce que chaque usage utilise : **rapide**, **standard**, **approfondi** et **voix**.
Les petits boutons **F / S / A / V** d'une ligne envoient cet usage vers ce modèle : le travail courant peut tourner sur
un petit modèle européen pendant que le raisonnement difficile va vers un modèle haut de gamme, chacun chez un fournisseur
différent. Le sélecteur *Routage* bascule entre le **défaut global** et un **réglage propre à l'espace** (les usages
modifiés sont marqués ; la réinitialisation revient au défaut global). Sans rien de réglé, tout se comporte comme avant.

### Les modèles d'embeddings
Les modèles d'embeddings (recherche dans la bibliothèque, classifieur, Mind) figurent dans le tableau pour comparer prix,
confiance et fournisseur, mais ils ne sont pas routés par usage. Celui qui sert affiche **✓ Actif** ; les autres renvoient à
**Choisir dans Réglages**, qui ouvre Réglages → *Recherche par le sens* (voir cette page). Le changer là réindexe la mémoire.

### Le catalogue se tient à jour
Une fois par semaine, et quand vous appuyez sur **Actualiser maintenant**, l'assistant demande à chaque fournisseur connecté
sa liste de modèles et ajoute ceux que le catalogue ne connaît pas encore (marqués comme estimations tant qu'un prix et un
rang ne sont pas confirmés). L'en-tête n'affiche une date de mise à jour qu'après une vraie actualisation ; avant, il
indique « Catalogue de base ».

### Le verrou Europe
Le bouton **Verrou Europe (RGPD)** est dans l'onglet Modèles (voir *Rester en Europe*). Il masque les modèles et fournisseurs
non conformes et fait refuser par Confidentialité tout service d'IA ou de recherche hors d'Europe.

### AKI.IO
Quand votre clé AKI.IO liste des modèles étiquetés **TL5**, les choix rapide/standard/approfondi sont pris uniquement parmi
eux : l'assistant est donc traité en Allemagne sans rien faire de plus. Choisir à la main un modèle non TL5 désactive ce choix.

## Niveaux et budget
- Trois niveaux : **rapide** (résumés, vérifications), **standard** (conversation), **approfondi** (raisonnements difficiles).
- **Budget** : limites par jour et par mois en dollars. Au-delà de 80 %, il passe au niveau rapide ;
  à 100 %, il n'appelle plus de modèle.
- Les coûts affichés sont des estimations à partir des prix publics. Une fois par mois, l'assistant demande ce que
  votre fournisseur a réellement facturé (ou saisissez-le dans Activité → la ligne sous les jauges) : le budget est alors
  corrigé par le rapport entre les vraies factures et les estimations, sur les trois dernières factures.
- **Coûts réels (Anthropic, OpenAI)** : avec une **clé Admin** (pas une clé API ordinaire : Console Anthropic →
  Settings → Admin keys, `sk-ant-admin…` ; OpenAI → Organization → Admin keys), l'assistant lit ce que le fournisseur
  a réellement facturé, toutes les 6 heures. Mettez-la dans Réglages → Modèle et budget → Budget pour les réglages
  principaux, ou sous *Coûts réels* dans un compte d'IA. Ajoutez un identifiant d'espace de travail (Anthropic) ou de
  projet (OpenAI) pour ne compter que cette partie de l'organisation. Les jours passés utilisent alors les vrais
  montants, l'estimation du jour est corrigée d'après les 30 derniers jours, et aucune facture n'est demandée.
  Activité montre ce que chaque source a facturé ce mois-ci (*Synchroniser* relit les chiffres). La clé Admin ne sert
  qu'à lire les coûts, reste sur le serveur et n'est jamais donnée à Claude Code ni aux outils. Mistral et Gemini
  n'ont pas cette API : ils gardent les estimations et les factures que vous saisissez.
- Avec Gemini gratuit, Google peut utiliser vos données et les limites sont basses : activez la facturation.
