# Apprendre l'API d'un site

Beaucoup de sites n'ont ni API publique ni connecteur, mais leurs propres pages chargent leurs données
depuis les serveurs du site (une recherche, vos commandes, un panier). L'assistant peut observer ces appels
pendant qu'il parcourt le site, et en faire un **connecteur** pour ce site : la fois suivante, il interroge
directement le site au lieu de cliquer de page en page.

## Comment faire
1. Demandez : « apprends l'API de boutique.example.com pour chercher des produits et voir mes commandes ».
2. L'assistant ouvre le site dans son navigateur et parcourt les pages qui font ce que vous avez demandé.
   Si le site demande d'être connecté, connectez-vous d'abord (« connecte-moi à boutique.example.com ») :
   l'assistant tape ce que vous lui donnez, et vous demande votre accord avant d'envoyer un formulaire de
   connexion.
3. Il lit les appels faits par les pages (`browser_api_calls`). Ce qu'il lit est **débarrassé des cookies,
   jetons, clés et mots de passe** ; les longues réponses sont raccourcies.
4. Il décrit les appels utiles (`site_api_save`) et vous demande votre **accord**. La demande indique :
   - l'adresse qu'il appellera, toujours ce site et rien d'autre ;
   - les appels qui ne font que **lire**, qui se feront sans demander ;
   - les appels qui **modifient** quelque chose (ajouter au panier, commander, envoyer…), qui vous
     demanderont à chaque fois ;
   - la façon de se connecter.
5. Une fois accepté, les outils du connecteur fonctionnent tout de suite, comme ceux de tout connecteur.

Ce qui est enregistré est une **description** des appels (adresses, paramètres), jamais un programme :
Talixio fait les appels lui-même.

## La connexion
- **Votre connexion au site** (cookies) : le connecteur utilise la connexion faite dans le navigateur de
  l'assistant. Quand elle expire, le connecteur le dit et l'assistant vous demande de **vous reconnecter**
  dans son navigateur ; rien d'autre à faire.
- **Une clé d'API** : l'assistant ne la demande jamais dans la conversation. Saisissez-la dans Réglages →
  Comptes connectés → **Connecteurs (MCP)** → *Appris des sites* → le connecteur → **Clé d'API** →
  *Enregistrer la clé*. Elle est conservée chiffrée. Une clé refusée : saisissez-en une nouvelle au même
  endroit.
- **Aucune** : des appels publics.

## Les voir et les retirer
Réglages → Comptes connectés → **Connecteurs (MCP)** → *Appris des sites* : chaque connecteur, son site, sa
façon de se connecter et ses appels (**demande d'abord** marque ceux qui modifient quelque chose).
**Retirer** le supprime avec ses outils. Demander à l'assistant d'apprendre à nouveau le même site le
remplace, et garde la clé saisie.

## Limites
- Cela ne marche que pour les sites dont les pages chargent leurs données par des appels à leur propre API
  (la plupart des sites récents). Un site qui construit ses pages sur son serveur n'a rien à apprendre :
  l'assistant utilise alors le navigateur.
- Un site qui garde son jeton de connexion dans la page elle-même (et non dans un cookie) n'est pas encore
  pris en charge.
- Les sites peuvent changer leur API à tout moment : quand les appels se mettent à échouer, demandez à
  l'assistant de l'apprendre à nouveau.
- Vérifiez les conditions d'utilisation du site : certains n'autorisent pas l'accès automatisé.
