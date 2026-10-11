# Liste des connecteurs

Des connecteurs (serveurs MCP) qui fonctionnent avec Talixio, triés par thème. Cliquez sur **▶ Installer** :
Talixio s'ouvre dans un nouvel onglet et prépare l'installation. Vous gardez la main à chaque étape.

- Pour un connecteur **sur GitHub**, la fenêtre *Installer un connecteur* s'ouvre et récupère le dépôt.
  Vérifiez ce qu'elle a trouvé, remplissez les réglages éventuels, puis cliquez sur *Installer*.
- Pour un **service en ligne**, Talixio vous demande de confirmer, puis l'ajoute et s'y connecte.

Vous pouvez aussi demander à l'assistant : « installe le connecteur Annuaire des entreprises ».

*Dernière vérification : 11 octobre 2026.* Ce sont des projets de la communauté, sauf mention contraire.
Un connecteur tourne dans votre assistant : n'installez que ce dont vous avez besoin.

## Données publiques françaises
- **data.gouv.fr (officiel)** : chercher et lire les jeux de données de la plateforme nationale.
  Service en ligne, sans compte, en lecture seule.
  [▶ Installer](?connector=https://mcp.data.gouv.fr/mcp&name=datagouv)
- **Annuaire des entreprises** : SIREN, SIRET, dirigeants, adresse, activité, depuis
  recherche-entreprises.api.gouv.fr. Sans clé.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-entreprises-fr)
- **INSEE Sirene** : le répertoire des entreprises et établissements.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-insee)
- **data.gouv.fr (API)** : une autre façon de chercher dans les jeux de données.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-datagouv-fr)
- **Base Adresse Nationale** : retrouver et normaliser une adresse, obtenir ses coordonnées.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-ban-fr)
- **BODACC** : les annonces légales (créations, ventes, procédures collectives).
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-bodacc)
- **BOAMP** : les avis de marchés publics.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-france-boamp)
- **DVF** : les ventes immobilières (demandes de valeurs foncières).
  [▶ Installer](?connector=https://github.com/NormiHQ/Normi/tree/HEAD/mcp-server/client)
- **SNCF Open Data** : gares, fréquentation, régularité, objets trouvés.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-data-sncf)
- **SNCF (horaires)** : trajets et prochains départs. Demande une clé gratuite de l'API SNCF
  (api.sncf.com). [▶ Installer](?connector=https://github.com/kryzo/sncf-mcp)
- **Opendatasoft** : les portails ouverts de nombreuses villes et régions.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-opendatasoft)
- **Portails CKAN** : n'importe quel portail de données ouvertes CKAN.
  [▶ Installer](?connector=https://github.com/ondata/ckan-mcp-server)
- **La Réunion** : les données ouvertes de La Réunion.
  [▶ Installer](?connector=https://github.com/Hug0x0/mcp-reunion)
- **NosDéputés.fr** : les députés, leurs interventions, leurs questions et leurs votes.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-nosdeputes-fr)

## Villes et régions
- **Paris** : les données ouvertes de la Ville de Paris (équipements, travaux, stationnement…).
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-data-paris)
- **Que faire à Paris** : expositions, concerts, sorties et activités de la Ville de Paris.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-paris-events)
- **Île-de-France** : les données ouvertes de la Région.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-data-iledefrance)
- **Sorties en Île-de-France** : les événements de la région.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-idf-events)
- **Toulouse Métropole** : [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-data-toulouse)
- **Grand Paris Sud** : [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-data-grandparissud)
- Les autres villes et régions (Marseille, Lyon, Nantes, Bordeaux…) publient souvent sur Opendatasoft ou
  data.gouv.fr : utilisez ces connecteurs, plus haut.

## Europe
- **Eurostat** : les statistiques européennes. Sans clé.
  [▶ Installer](?connector=https://github.com/mrfentmen/eurostat-mcp)
- **Eurostat (autre version)** : [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-eurostat)
- **EUR-Lex** : les textes du droit européen.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-eur-lex)
- **Opendata.cat** : les données ouvertes de Catalogne.
  [▶ Installer](?connector=https://github.com/xaviviro/Opendata.cat-MCP-Server)
- **Bruxelles** : les données ouvertes de la Ville de Bruxelles.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-data-brussels)

## Cartes, trajets et transports
Des connecteurs sans compte, à partir d'OpenStreetMap et des données ouvertes.

- **Adresses (Nominatim)** : trouver un lieu ou une adresse partout dans le monde, ou l'adresse d'un point.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-nominatim)
- **Lieux autour de moi (Overpass)** : musées, restaurants, pharmacies, gares, parkings… autour d'un point
  ou dans un quartier. [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-overpass)
- **Itinéraires (OSRM)** : trajet et temps de parcours en voiture, entre plusieurs points.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-osrm)
- **Vélos en libre-service** : stations et vélos disponibles (Vélib', Vélo'v, V'Lille…).
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-citybikes)
- **Trains en Europe** : horaires, départs et trajets, surtout en Allemagne et dans les pays voisins.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-db-rail)
- **Réseaux de transport** : trouver les données d'horaires (GTFS) d'un réseau de bus, métro ou tram.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-mobility-database)

## Météo
- **Météo (Open-Meteo)** : météo actuelle, prévisions et historique, partout. Sans clé.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-weather)

## Énergie et économie
- **ODRE** : production et consommation d'électricité et de gaz en France (RTE, GRDF, Enedis…).
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-data-odre)
- **Prix de l'électricité en Europe** : prix du marché, production par source, part du renouvelable.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-energy-charts)
- **data.economie.gouv.fr** : les données du ministère de l'Économie, dont les prix des carburants.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-data-economie-fr)

## Actualités et flux RSS
- **RSS** : lire n'importe quel flux RSS ou Atom. Sans clé.
  [▶ Installer](?connector=https://github.com/mrfentmen/rss-mcp)
- **Flux RSS (filtres)** : lire, filtrer et chercher dans plusieurs flux.
  [▶ Installer](?connector=https://github.com/aashish/rss-mcp-server)

## Banque
- **Qonto (officiel)** : comptes, transactions, factures, virements de votre compte pro. Service en ligne :
  après l'ajout, cliquez sur **Se connecter** dans Réglages → Connecteurs. Les opérations sensibles se
  valident dans l'application Qonto. [▶ Installer](?connector=https://mcp.qonto.com/mcp&name=qonto)

## Films, séries, télévision et cinéma
- **TMDB (films et séries)** : chercher un film ou une série, les notes, les recommandations, et **où le
  regarder** (Netflix, Prime Video, Disney+, Canal+…) : « qu'y a-t-il de nouveau sur Netflix ? ».
  Demande un jeton gratuit de themoviedb.org (*Read Access Token*). Mettez `TMDB_REGION` à `FR` et
  `TMDB_LANGUAGE` à `fr-FR`. [▶ Installer](?connector=https://github.com/Grinv/tmdb-mcp)
- **Séries et programme TV (TVmaze)** : chercher une série, et le programme télé d'un jour par pays.
  Sans clé. Pour la France, le programme est partiel (surtout les séries).
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-movies)
- **Allociné** : films à l'affiche, cinémas proches d'une ville ou d'un code postal, séances (VF, VOST,
  3D, IMAX), dont les salles Pathé, Gaumont et UGC. Il lit le site Allociné, sans accord officiel : il peut
  cesser de fonctionner. [▶ Installer](?connector=https://github.com/Racimy/Allocin-MCP)

## Services en ligne (SaaS)
Les services officiels ci-dessous se connectent avec votre compte : une fois ajouté, cliquez sur **Se connecter**
dans Réglages → Connecteurs. L'assistant n'a accès qu'à ce que vous autorisez.

- **Notion (officiel)** : chercher, lire et écrire vos pages et bases de données.
  [▶ Installer](?connector=https://mcp.notion.com/mcp&name=notion)
- **Linear (officiel)** : tickets, projets et cycles.
  [▶ Installer](?connector=https://mcp.linear.app/mcp&name=linear)
- **monday.com (officiel)** : tableaux, éléments et mises à jour.
  [▶ Installer](?connector=https://mcp.monday.com/mcp&name=monday)
- **Airtable (officiel)** : vos bases, tables et enregistrements.
  [▶ Installer](?connector=https://mcp.airtable.com/mcp&name=airtable)
- **Todoist (officiel)** : vos tâches et projets.
  [▶ Installer](?connector=https://ai.todoist.net/mcp&name=todoist)
- **Miro (officiel)** : lire et créer sur vos tableaux blancs.
  [▶ Installer](?connector=https://mcp.miro.com/&name=miro)
- **Canva (officiel)** : chercher et créer des designs.
  [▶ Installer](?connector=https://mcp.canva.com/mcp&name=canva)
- **Gamma (officiel)** : créer des présentations et des documents.
  [▶ Installer](?connector=https://mcp.gamma.app/mcp&name=gamma)
- **Calendly (officiel)** : vos types de rendez-vous, créneaux et réservations.
  [▶ Installer](?connector=https://mcp.calendly.com/&name=calendly)
- **Trello** : tableaux, listes et cartes. Demande une clé et un jeton Trello (trello.com/power-ups/admin).
  [▶ Installer](?connector=https://github.com/endlesshoper/trello-mcp)
- **Stripe (officiel)** : paiements, clients, factures, abonnements.
  [▶ Installer](?connector=https://mcp.stripe.com/&name=stripe)
- **PayPal (officiel)** : factures, commandes, litiges et transactions.
  [▶ Installer](?connector=https://mcp.paypal.com/mcp&name=paypal)
- **Wix (officiel)** : gérer votre site Wix (pages, boutique, réservations).
  [▶ Installer](?connector=https://mcp.wix.com/mcp&name=wix)
- **Hugging Face (officiel)** : chercher des modèles, des jeux de données et des articles. Sans compte.
  [▶ Installer](?connector=https://hf.co/mcp&name=huggingface)
- **Wolfram (officiel)** : calculs, conversions, données scientifiques et pratiques.
  [▶ Installer](?connector=https://agenttools.wolfram.com/mcp&name=wolfram)
- **Zapier et Make** : relient des milliers d'applications (Google Drive, Dropbox, Gmail, Trello…). L'adresse est
  propre à votre compte : créez votre serveur sur mcp.zapier.com (ou dans Make), copiez son adresse, puis
  ajoutez-la dans Réglages → Connecteurs. Pas de lien direct ici.

## Voyages et sorties
- **Ferryhopper (officiel)** : horaires et prix des ferries en Europe (Grèce, Italie, Corse…). Sans compte.
  [▶ Installer](?connector=https://mcp.ferryhopper.com/mcp&name=ferryhopper)
- **Peek (officiel)** : activités, visites et excursions à réserver. Sans compte.
  [▶ Installer](?connector=https://mcp.peek.com/&name=peek)

## Emploi
- **France Travail (ex-Pôle emploi)** : offres d'emploi (dont celles des agences d'intérim), fiches métiers
  (ROME) et, en option, les entreprises qui recrutent (La Bonne Boîte). Utilise les API officielles : créez un
  compte gratuit sur francetravail.io, abonnez une application à l'API *Offres d'emploi*, puis renseignez
  `FT_CLIENT_ID` et `FT_CLIENT_SECRET`. [▶ Installer](?connector=https://github.com/jojolabagarre/france-travail-mcp)
- **Offres en Europe (Arbeitnow)** : offres d'emploi, surtout en Allemagne et en Europe. Sans clé.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-arbeitnow)
- **Télétravail (Remotive)** : offres en télétravail, dans le monde entier. Sans clé.
  [▶ Installer](?connector=https://github.com/pipeworx-io/mcp-remotive)

## Courses et livraison (expérimental)
Ces services n'ont pas d'API publique. Ces connecteurs se font passer pour un navigateur et peuvent cesser de
fonctionner à tout moment. Utilisez un compte dont vous acceptez le risque.

- **Uber Eats** : chercher des restaurants, lire les menus, commander. Il pilote un navigateur, qui peut
  manquer dans votre installation.
  [▶ Installer](?connector=https://github.com/markswendsen-code/mcp-ubereats)
- **E.Leclerc Drive** : chercher des produits, remplir le panier. Demande Node.js 22 ou plus récent.
  [▶ Installer](?connector=https://github.com/skunkobi/mcp-leclerc-drive)
- **Supermarchés suisses** : Migros, Coop, Aldi, Denner, Lidl Suisse… : produits, prix et promotions.
  [▶ Installer](?connector=https://github.com/nicktcode/swissgroceries-mcp)

## Pas encore disponibles
- **Carrefour** : les connecteurs existants passent par un vrai navigateur pour contourner la protection
  du site. Talixio ne sait pas les installer.
- **Uber (courses en VTC)** : Uber a une API web classique, réservée aux partenaires, mais pas de serveur MCP.
- **McDonald's** : un service officiel existe en Chine seulement (mcp.mcd.cn, avec un jeton personnel).
  Rien pour la France.
- **Lidl France, Intermarché, Auchan, Deliveroo** : rien d'utilisable trouvé.
- **Programme TV complet des chaînes françaises** : pas de connecteur. TVmaze, plus haut, en donne une
  partie. L'assistant peut aussi lire une page de programme TV sur le web, ou un flux RSS de programmes avec
  le connecteur RSS.
- **Fnac, Darty, Cdiscount (achats en ligne)** : rien d'utilisable. Pour la Fnac, seulement un service
  payant (Apify).
- **Cinémas Pathé, Gaumont, UGC** : pas de connecteur officiel ; Allociné, plus haut, donne leurs séances.
- **EDF, Engie, GRDF, TotalEnergies** : pas de connecteur. Un connecteur Enedis Linky (votre consommation)
  existe, écrit en Go : Talixio ne sait pas encore l'installer. ODRE, plus haut, donne les chiffres
  nationaux.
- **SeLoger, PAP, Leboncoin (immobilier)** : rien pour SeLoger et PAP. Les connecteurs Leboncoin sont en
  Python et bloqués par la protection du site. DVF, plus haut, donne les prix de vente réels.
- **Voitures (Aramisauto, La Centrale, Vendezvotrevoiture, L'argus)** : rien d'installable. Un service
  CarHunt (annonces Leboncoin, La Centrale…) s'annonce, sans avoir pu être vérifié.
- **RATP, Île-de-France Mobilités, RTM et autres réseaux** : un connecteur Île-de-France Mobilités existe
  (Python, avec une clé PRIM), Talixio ne sait pas encore l'installer. Rien pour la RTM ni les autres
  réseaux : *Réseaux de transport*, plus haut, trouve leurs horaires publiés.
- **Google Maps, Waze** : rien pour Waze. Les connecteurs Google Maps demandent une clé Google Cloud
  (facturée au-delà d'un quota gratuit) ; utilisez plutôt ceux d'OpenStreetMap, plus haut.
- **Agences de voyage, Airbnb, Booking, vols** : pas d'agence. Le connecteur Airbnb trouvé respecte les
  règles du site, qui le bloquent. Les connecteurs de vols demandent une clé payante.
- **Offices de tourisme, mairies, musées** : pas de connecteur dédié en France. Leurs données passent
  souvent par data.gouv.fr, Opendatasoft ou les connecteurs des villes ; Overpass trouve les musées autour
  de vous. Un connecteur du Louvre existe, sans avoir pu être vérifié.
- **Radio France** (France Inter, franceinfo, France Culture, FIP…) : l'API officielle donne les grilles
  et les podcasts, avec une clé gratuite. Un connecteur de la communauté existe, mais il n'a pas pu être
  vérifié.
- **Amazon (achats)** : seulement un service payant (HasData). Pour Prime Video, voir TMDB.
- **Autres banques et agrégateurs** (Powens, Bridge, Linxo, Nickel, Shine, banques de réseau) : pas de
  connecteur officiel. BankMCP lit vos comptes de presque toutes les banques européennes en lecture seule,
  par l'open banking (Enable Banking), mais il demande Node.js 24, absent de l'installation standard.
- **Google Drive, Dropbox, Box** : Google a un serveur officiel pour Drive, mais il faut créer sa propre
  application dans Google Cloud. Celui de Dropbox n'accepte que certaines applications. Pour Box, il faut aussi
  créer une application. Le plus simple : passer par Zapier ou Make, ci-dessus.
- **Asana, Jira et Confluence (Atlassian), Slack, ClickUp, Figma, GitHub** : leurs serveurs officiels
  demandent de créer d'abord une application chez eux (identifiant client). C'est possible avec Talixio
  (réglage `oauth` du connecteur), mais réservé aux habitués.
- **LinkedIn** : pas de connecteur officiel. Ceux de la communauté lisent le site avec votre session, contre
  ses conditions d'utilisation, et risquent de faire bloquer votre compte.
- **Indeed** : le serveur officiel est réservé à Claude.
- **APEC, Cadremploi, Hellowork, Welcome to the Jungle, agences d'intérim (Adecco, Manpower, Randstad…)** :
  rien de disponible. Beaucoup de leurs offres sont aussi publiées sur France Travail, ci-dessus.
- **Légifrance** : pas de connecteur en ligne trouvé. EUR-Lex couvre le droit européen.
- Les serveurs **Python avec des dépendances** ne s'installent pas encore.

Il en manque un ? Demandez à l'assistant de chercher un serveur MCP pour ce service, puis donnez-lui
l'adresse GitHub trouvée. Voir aussi *Connecteurs / Installer un connecteur*.
