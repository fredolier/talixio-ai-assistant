# Où vont mes données

La page **Confidentialité** montre :
- le niveau global : **Local** (rien ne sort), **Entreprise** (seuls des services sous contrat pro,
  sans entraînement sur vos données) ou **Personnel** ;
- chaque service utilisé, avec ce qu'il reçoit, ce qu'il peut en faire, et un bouton pour corriger quand c'est possible ;
- la sécurité : HTTPS, connexion en deux étapes, sauvegardes ;
- le **journal** de chaque envoi vers l'extérieur (service, usage, taille, espace ; jamais le contenu),
  exportable en CSV, conservé 180 jours ;
- ce que l'assistant a fait dans la conversation (ses outils et leurs résultats, dans *Activité*) est gardé 90 jours
  (Réglages → Budget, *Garder le journal des actions de l'assistant*) ; les messages échangés avec d'autres assistants
  et entre agents sont supprimés quelques mois après leur livraison ; les coûts restent, additionnés par jour après 90 jours ;
- un **registre des traitements (RGPD)** pré-rempli, à télécharger et compléter.

Pour passer au niveau Entreprise, en général :
- clé Gemini sur une offre payante (ou transcription locale) ;
- notifications sans contenu si vous utilisez Telegram ;
- mot de passe sur les liens de partage.

Pour que rien ne sorte d'Europe : voir *Rester en Europe*.

Sur un serveur à plusieurs (Docker), chaque personne a ses propres données ; l'administrateur du
serveur peut techniquement y accéder.

## Les secrets enregistrés sont chiffrés
Vos clés d'API, mots de passe (e-mail, agenda), jetons de connexion (Google, Microsoft, Spotify, connecteurs), le
secret de la connexion en deux étapes et les secrets des comptes d'IA et des assistants appairés sont gardés
**chiffrés** dans le dossier de données. La clé n'est pas dans ce dossier : sur une installation simple, c'est
`/etc/talixio/secret.key` (créée par l'installeur) ; sur un serveur à plusieurs (Docker), chaque assistant a la
sienne, donnée par le serveur. Une copie du seul dossier de données (une sauvegarde, un disque) ne donne pas vos secrets.
- **Sauvegardez la clé à part des données** (un gestionnaire de mots de passe, une clé USB). Sans elle, une
  sauvegarde restaurée sur un autre serveur revient sans ses secrets : vous les saisissez à nouveau (tout le reste est là).
- *Réglages → Lancer les vérifications* indique si les secrets sont chiffrés, et combien ne s'ouvrent pas avec cette clé.
- Si la clé manque ou n'est pas la bonne, rien n'est effacé : remettez la bonne clé et tout s'ouvre à nouveau.
- Non chiffré : ce que d'autres parties gardent elles-mêmes (la connexion propre de Claude Code, les fichiers de la
  page d'administration multi-utilisateur), et le mot de passe, gardé de toute façon seulement sous forme d'empreinte.
