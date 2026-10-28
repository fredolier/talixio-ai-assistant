# Choisir le modèle (le « cerveau »)

## Le plus simple : se connecter avec Claude
Avec un abonnement Claude Pro ou Max : Réglages → *Modèle d'IA* → **Se connecter avec Claude**
(ou le bouton affiché dans la conversation tant qu'aucun modèle n'est réglé).
1. Ouvrez la page de connexion de Claude et acceptez.
2. Claude affiche un code : copiez-le et collez-le dans Talixio, puis *Connecter*.
C'est la connexion officielle de Claude Code (installé sur le serveur, et inclus dans l'image Docker) ;
Talixio ne voit jamais votre mot de passe. Le jeton obtenu vaut un an.

L'utilisation compte dans les limites de votre abonnement. Les conditions d'Anthropic sur l'usage d'un
abonnement par des outils comme celui-ci ont changé plusieurs fois en 2026 : vérifiez-les.
Pour un usage professionnel, une **clé d'API** (facturée à l'usage, sous contrat pro) reste la voie la plus sûre.

## Les autres choix
Réglages → *Modèle d'IA* :

| Option | Réglage |
|---|---|
| API Claude | fournisseur `anthropic`, clé `ANTHROPIC_API_KEY` |
| Abonnement Claude Pro/Max | `--with-claude-code`, puis `claude setup-token` ; fournisseur `claude-code` |
| API Gemini | fournisseur `gemini`, clé `GEMINI_API_KEY` (https://aistudio.google.com/apikey) |
| OpenAI | fournisseur `openai`, clé `OPENAI_API_KEY` |
| Fournisseur européen (OVHcloud, Mistral, Scaleway, Infomaniak, AKI.IO) | carte *Europe only* (voir *Rester en Europe*) ; fournisseur `sovereign` |
| Modèle local (Ollama…) | fournisseur `openai`, adresse `http://localhost:11434/v1`, nom du modèle |

## Un fournisseur différent par espace (comptes d'IA)
Réglages → *Modèle d'IA* → **Comptes d'IA** → *+ Ajouter un compte*. Chaque compte a un nom, un fournisseur et sa clé ou son jeton.
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

- Trois niveaux : **rapide** (résumés, vérifications), **standard** (conversation), **approfondi** (raisonnements difficiles).
- **Budget** : limites par jour et par mois en dollars. Au-delà de 80 %, il passe au niveau rapide ;
  à 100 %, il n'appelle plus de modèle.
- Les coûts affichés sont des estimations à partir des prix publics. Une fois par mois, l'assistant demande ce que
  votre fournisseur a réellement facturé (ou saisissez-le dans Activité → la ligne sous les jauges) : le budget est alors
  corrigé par le rapport entre les vraies factures et les estimations, sur les trois dernières factures.
- **Coûts réels (Anthropic, OpenAI)** : avec une **clé Admin** (pas une clé API ordinaire : Console Anthropic →
  Settings → Admin keys, `sk-ant-admin…` ; OpenAI → Organization → Admin keys), l'assistant lit ce que le fournisseur
  a réellement facturé, toutes les 6 heures. Mettez-la dans Réglages → Modèle d'IA → Budget pour les réglages
  principaux, ou sous *Coûts réels* dans un compte d'IA. Ajoutez un identifiant d'espace de travail (Anthropic) ou de
  projet (OpenAI) pour ne compter que cette partie de l'organisation. Les jours passés utilisent alors les vrais
  montants, l'estimation du jour est corrigée d'après les 30 derniers jours, et aucune facture n'est demandée.
  Activité montre ce que chaque source a facturé ce mois-ci (*Synchroniser* relit les chiffres). La clé Admin ne sert
  qu'à lire les coûts, reste sur le serveur et n'est jamais donnée à Claude Code ni aux outils. Mistral et Gemini
  n'ont pas cette API : ils gardent les estimations et les factures que vous saisissez.
- Avec Gemini gratuit, Google peut utiliser vos données et les limites sont basses : activez la facturation.
