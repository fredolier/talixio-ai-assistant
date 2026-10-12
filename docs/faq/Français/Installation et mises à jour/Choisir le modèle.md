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
| Fournisseur européen (OVHcloud, Mistral, Scaleway, Infomaniak) | carte *Europe only* (voir *Rester en Europe*) ; fournisseur `sovereign` |
| Modèle local (Ollama…) | fournisseur `openai`, adresse `http://localhost:11434/v1`, nom du modèle |

- Trois niveaux : **rapide** (résumés, vérifications), **standard** (conversation), **approfondi** (raisonnements difficiles).
- **Budget** : limites par jour et par mois en dollars. Au-delà de 80 %, il passe au niveau rapide ;
  à 100 %, il n'appelle plus de modèle.
- Les coûts affichés sont des estimations à partir des prix publics. Une fois par mois, l'assistant demande ce que
  votre fournisseur a réellement facturé (ou saisissez-le dans Activité → la ligne sous les jauges) : le budget est alors
  corrigé par le rapport entre les vraies factures et les estimations, sur les trois dernières factures.
- Avec Gemini gratuit, Google peut utiliser vos données et les limites sont basses : activez la facturation.
