# La bibliothèque (vos documents)

## Ajouter des documents
- **Téléverser** ou glisser-déposer dans la page Bibliothèque, dans le dossier ouvert.
- Les pièces jointes de la conversation, de Telegram ou de Signal arrivent dans `/Inbox` ;
  les enregistrements dans `/Meetings`.
- Sur Android : **Partager → Talixio** depuis n'importe quelle app.

## Ranger
- L'app propose un dossier et un nom clair pour chaque nouveau document de `/Inbox`
  (« scan_0012.pdf → /Admin/Factures/Facture EDF 2026-10.pdf ») : *Ranger*, *Modifier…* ou *Tout ranger*.
  Elle apprend de vos rangements. Réglages → *Rangement des nouveaux documents* : proposer, ranger
  les évidents, ou désactivé.
- Dossiers : créer, renommer, déplacer, supprimer (à la corbeille) ; un dossier se télécharge en ZIP.
- L'arborescence se **plie et se déplie** (la flèche devant chaque dossier ; le bouton en haut replie tout).
  Le chemin du dossier ouvert reste déplié, et l'état est retenu sur cet appareil. En glissant un fichier,
  un dossier replié s'ouvre si l'on reste dessus un instant.

## Retrouver
- Tout est lu et indexé en arrière-plan : PDF (y compris scannés), Word/Excel/PowerPoint, e-mails,
  texte, audio (transcrit), photos (décrites, texte lu).
- Cherchez dans la page, ou demandez à l'assistant : « d'après mes documents dans /Clients/Alice, … ».
- **« Où est… ? »** : la recherche de la page montre d'abord les dossiers et fichiers dont le **nom** correspond
  (sans tenir compte des accents ni des majuscules ; un clic sur un dossier l'ouvre), puis ceux dont le contenu
  correspond. Dans la discussion, « où est le contrat Dupont ? » donne le chemin et le lien.
- La recherche se fait en deux temps : les documents qui contiennent **les mots** s'affichent tout de suite,
  puis la recherche **par le sens** (modèle local) complète la liste quelques secondes plus tard. Un spinner
  l'indique ; *Annuler* (ou Échap) l'arrête en gardant ce qui est déjà trouvé. Changer de dossier ou lancer
  une autre recherche arrête la précédente ; *Fermer la recherche* revient au dossier.
  Il cite le document et la page ; un clic l'ouvre à cette page.
- *Demander à propos de ce dossier* lance une question sur ses documents.

## Supprimer, la corbeille
- *Supprimer* (sur un fichier ouvert, ou sur un dossier avec ses fichiers) met à la **corbeille**, pas
  à la poubelle définitive. Ce qui y est sort des recherches, de l'assistant et des liens de partage.
- La **Corbeille** est en bas de l'arborescence (sur téléphone : le bouton *Corbeille* en haut de la page).
  Elle montre d'où vient chaque fichier, quand il a été supprimé et combien de jours il reste.
- *Restaurer* le remet à sa place (le dossier est recréé s'il le faut). Un dossier supprimé revient
  d'un bloc, avec ses fichiers rangés dans leurs sous-dossiers. Rien n'est relu ni retranscrit : il redevient cherchable aussitôt.
- Au bout de **30 jours**, un fichier est effacé pour de bon. *Vider la corbeille* le fait tout de suite
  (deux clics). Un fichier effacé ne se retrouve plus que dans une sauvegarde de la nuit (7 gardées).
- L'assistant voit la corbeille et peut restaurer : « retrouve le devis que j'ai supprimé hier ».
- Les fichiers supprimés avant la version 0.19.0 n'y sont pas : ils étaient effacés tout de suite.

## Notes
*Nouvelle note* crée une note Markdown modifiable. L'assistant peut aussi en enregistrer (`kb_save_note`).

## Le dossier /FAQ
Il est en lecture seule et remis à jour à chaque version : ces pages en font partie.
