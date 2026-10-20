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

## Un nom par dossier, les versions
- Un nom n'est utilisé qu'une fois par dossier (majuscules comprises). Envoyer un fichier sous un nom déjà présent,
  l'assistant qui enregistre de nouveau une note, ou un enregistrement depuis le lecteur réseau **remplace** ce fichier :
  même fichier, mêmes liens et partages, avec le nouveau contenu.
- L'ancien contenu est gardé comme **version** : la section *Versions* du fichier ouvert les liste (date, taille),
  avec *Télécharger* et *Restaurer* (le contenu actuel devient à son tour une version). Les versions sont gardées
  **30 jours**, 20 au plus par fichier. L'assistant peut aussi les lister et les restaurer (« remets la version d'hier du plan »).
- *Renommer* ou *Déplacer* vers un nom déjà pris demande : **Remplacer** (ce fichier prend ce contenu, l'ancien devient
  une version) ou **Garder les deux** (celui-ci s'appelle « nom (2) »).
- Les pièces jointes du chat gardent les deux (« image.png », « image (2).png »).
- Les fichiers qui portaient le même nom dans un dossier avant la version 0.28.29 ont été fusionnés une fois :
  le plus récent est gardé, les autres sont ses versions.

## Modifications récentes
- *Modifications récentes* (en haut de la Bibliothèque) liste les fichiers créés ou modifiés dans la dernière heure,
  aujourd'hui, sur 2 jours ou 7 jours : dossier, ce qui s'est passé (créé, modifié, déplacé, renommé, restauré),
  par qui (**vous**, **l'assistant**, **une routine**, **le lecteur réseau**) et quand. Un point signale un fichier
  modifié par un autre que vous et pas ouvert depuis ; *Tout marquer comme vu* les efface. Cliquez pour l'ouvrir.
- **Notifications** : une notification groupée dans l'app, au plus toutes les heures (ou une fois par jour, ou
  jamais : le menu en bas), pour les fichiers modifiés *par d'autres* — jamais pour vos propres modifications.
  *Acquitter* efface le point d'un fichier ; *Ne plus notifier* exclut un dossier des notifications (*Notifier à nouveau* l'y remet).
- Le **briefing du matin** a une ligne du type « Depuis hier : 3 nouveaux fichiers et 2 modifiés ».
- Demandez à l'assistant : « qu'est-ce qui a changé dans ma bibliothèque aujourd'hui ? ».
- Les fichiers d'un espace Secret n'apparaissent que dans cet espace. Les modifications antérieures à cette
  fonction n'affichent que la date de création.

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

*Modifier* sur un fichier Markdown ouvre un éditeur visuel : titres, listes, cases à cocher, tableaux et liens
s'affichent comme à la lecture. Tapez `/` sur une ligne vide pour les titres, listes, citations, code, tableaux ou
un séparateur ; sélectionnez du texte pour la barre de mise en forme ; dans un tableau, ajoutez ou déplacez lignes
et colonnes depuis ses poignées. **Markdown** bascule sur la source (les fichiers avec en-tête YAML ou HTML
s'ouvrent dessus). *Plein écran* donne toute la fenêtre à l'éditeur ; Échap ferme d'abord un menu, puis en sort.
Un fichier ouvert puis enregistré sans modification reste exactement tel quel.

## Le dossier /FAQ
Il est en lecture seule et remis à jour à chaque version : ces pages en font partie.
