# Choisir le modèle (le « cerveau »)

Réglages → *Modèle d'IA* :

| Option | Réglage |
|---|---|
| API Claude | fournisseur `anthropic`, clé `ANTHROPIC_API_KEY` |
| Abonnement Claude Pro/Max | `--with-claude-code`, puis `claude setup-token` ; fournisseur `claude-code` |
| API Gemini | fournisseur `gemini`, clé `GEMINI_API_KEY` (https://aistudio.google.com/apikey) |
| OpenAI | fournisseur `openai`, clé `OPENAI_API_KEY` |
| Modèle local (Ollama…) | fournisseur `openai`, adresse `http://localhost:11434/v1`, nom du modèle |

- Trois niveaux : **rapide** (résumés, vérifications), **standard** (conversation), **approfondi** (raisonnements difficiles).
- **Budget** : limites par jour et par mois en dollars. Au-delà de 80 %, il passe au niveau rapide ;
  à 100 %, il n'appelle plus de modèle.
- Les coûts affichés sont des estimations à partir des prix publics.
- Avec Gemini gratuit, Google peut utiliser vos données et les limites sont basses : activez la facturation.
