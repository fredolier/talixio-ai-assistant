# Prévisions et décisions

Deux modèles spécialisés se tiennent à côté du modèle de langage. Un modèle de langage est bon avec les mots, pas avec les chiffres :
quand vous voulez les prochains mois d'une courbe, ou une décision avec une probabilité honnête, l'assistant confie le travail au bon outil.

## Prévisions (Google TimesFM)
Donnez des chiffres à l'assistant — collés dans la conversation, ou lus dans un fichier — et demandez la suite : *« Voici nos ventes
mensuelles sur trois ans, qu'attend-on pour les six prochains mois ? »*. Il répond avec la prévision et un intervalle à 80 % (la
vraie valeur devrait y tomber environ quatre fois sur cinq).

- **Série :** la valeur la plus ancienne d'abord, au moins 3 nombres (jusqu'à 5 000). Si les données se répètent (12 pour des chiffres
  mensuels avec un motif annuel, 7 pour des chiffres quotidiens avec un motif hebdomadaire), dites-le ; l'assistant le transmet.
- **Sans aucune installation**, l'assistant utilise une méthode simple intégrée (saisonnière naïve avec dérive, ou tendance amortie)
  et le dit dans sa réponse. Honnête, mais modeste.
- **Avec TimesFM** (le modèle de fondation de Google pour les séries chronologiques, environ 200 millions de paramètres, licence
  ouverte, tourne sur le processeur du serveur — pas de carte graphique) les prévisions sont meilleures, surtout sur des séries
  courtes ou irrégulières. La réponse nomme le modèle.
- L'assistant n'invente jamais de chiffres : si vous n'en avez pas donné, il les demande.

### Activer TimesFM (installation Docker)
1. Relancez `install-stack.sh --with-models` depuis le clone. Il construit le service (environ 2 Go de mémoire quand il travaille ;
   les poids se téléchargent à la première prévision demandée, la première est donc lente).
2. Dans **Réglages → Modèle et budget → Prévisions et décisions**, renseignez *Adresse du service TimesFM* : `http://talixio-caddy:8765`
   (les instances créées depuis la page d'administration la reçoivent automatiquement).

Si le service ne répond pas, l'assistant se rabat sur la méthode intégrée et vous le dit.

## Décisions (Jev, Kev et consorts)
Certains modèles n'écrivent pas de texte : ils répondent à des **questions typées** sur une situation — choisir une option, donner une
note sur des niveaux ordonnés, ou répondre oui/non — et rendent des **probabilités et une confiance**. *Jev*, de TypeSafe, en est un ;
il existe des modèles ouverts qui parlent le même protocole (POST `/v1/systemone`), comme *Kev* (Apache-2.0) et *Rizzo Flow*.

Dans **Réglages → Modèle et budget → Prévisions et décisions** :
- *Adresse du modèle de décision* — le serveur : le cloud de TypeSafe (**votre texte part alors chez eux** ; les règles de
  confidentialité de Talixio s'appliquent et peuvent le bloquer) ou votre propre serveur Kev / Rizzo Flow.
- *Clé du modèle de décision* — envoyée comme jeton Bearer ; vide si votre serveur n'en a pas.
- *Nom du modèle de décision* — facultatif, p. ex. `kev-latest`.

Rien n'est envoyé tant que l'adresse est vide, et la capacité *decide* n'apparaît pas. Une fois renseigné, demandez : *« Faut-il accepter
la demande de remise de ce client ? Options : accepter, contre-proposer, refuser. »* La réponse donne la probabilité de chaque option et la
confiance du modèle, présentées comme venant du modèle de décision, et non d'une estimation du modèle de langage.
