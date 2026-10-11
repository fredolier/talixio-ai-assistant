# Connector list

Connectors (MCP servers) that work with Talixio, by topic. Click **▶ Install**: Talixio opens in a new tab
and prepares the installation. You stay in control at every step.

- For a connector **on GitHub**, the *Install a connector* window opens and fetches the repository.
  Check what it found, fill in any settings, then click *Install*.
- For an **online service**, Talixio asks you to confirm, then adds it and connects to it.

You can also ask the assistant: "install the French company directory connector".

*Last checked: 11 October 2026.* These are community projects unless stated otherwise.
A connector runs inside your assistant: only install what you need.

## French public data
- **data.gouv.fr (official)**: search and read the datasets of the national platform.
  Online service, no account, read-only.
  [▶ Install](?connector=https://mcp.data.gouv.fr/mcp&name=datagouv)
- **French company directory**: SIREN, SIRET, executives, address, activity, from
  recherche-entreprises.api.gouv.fr. No key.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-entreprises-fr)
- **INSEE Sirene**: the register of companies and establishments.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-insee)
- **data.gouv.fr (API)**: another way to search the datasets.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-datagouv-fr)
- **Base Adresse Nationale**: find and normalise a French address, get its coordinates.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-ban-fr)
- **BODACC**: legal notices (new companies, sales, insolvency).
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-bodacc)
- **BOAMP**: public procurement notices.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-france-boamp)
- **DVF**: property sales in France.
  [▶ Install](?connector=https://github.com/NormiHQ/Normi/tree/HEAD/mcp-server/client)
- **SNCF Open Data**: stations, passenger numbers, punctuality, lost property.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-data-sncf)
- **SNCF (timetables)**: journeys and next departures. Needs a free SNCF API key (api.sncf.com).
  [▶ Install](?connector=https://github.com/kryzo/sncf-mcp)
- **Opendatasoft**: the open-data portals of many cities and regions.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-opendatasoft)
- **CKAN portals**: any CKAN open-data portal.
  [▶ Install](?connector=https://github.com/ondata/ckan-mcp-server)
- **La Réunion**: open data of La Réunion.
  [▶ Install](?connector=https://github.com/Hug0x0/mcp-reunion)
- **NosDéputés.fr**: members of the French Assemblée nationale, their speeches, questions and votes.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-nosdeputes-fr)

## Cities and regions
- **Paris**: open data of the City of Paris (facilities, roadworks, parking…).
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-data-paris)
- **What's on in Paris**: exhibitions, concerts, outings and activities from the City of Paris.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-paris-events)
- **Île-de-France**: open data of the Paris region.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-data-iledefrance)
- **Events in Île-de-France**: the region's events.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-idf-events)
- **Toulouse Métropole**: [▶ Install](?connector=https://github.com/pipeworx-io/mcp-data-toulouse)
- **Grand Paris Sud**: [▶ Install](?connector=https://github.com/pipeworx-io/mcp-data-grandparissud)
- Other cities and regions (Marseille, Lyon, Nantes, Bordeaux…) often publish on Opendatasoft or
  data.gouv.fr: use those connectors, above.

## Europe
- **Eurostat**: European statistics. No key.
  [▶ Install](?connector=https://github.com/mrfentmen/eurostat-mcp)
- **Eurostat (another version)**: [▶ Install](?connector=https://github.com/pipeworx-io/mcp-eurostat)
- **EUR-Lex**: European Union law.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-eur-lex)
- **Opendata.cat**: open data of Catalonia.
  [▶ Install](?connector=https://github.com/xaviviro/Opendata.cat-MCP-Server)
- **Brussels**: open data of the City of Brussels.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-data-brussels)

## Maps, journeys and transport
No account needed, from OpenStreetMap and open data.

- **Addresses (Nominatim)**: find a place or an address anywhere in the world, or the address of a point.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-nominatim)
- **Places near me (Overpass)**: museums, restaurants, pharmacies, stations, car parks… around a point or
  in an area. [▶ Install](?connector=https://github.com/pipeworx-io/mcp-overpass)
- **Routes (OSRM)**: driving route and travel time, between several points.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-osrm)
- **Bike sharing**: stations and available bikes (Vélib', Vélo'v, Santander Cycles…).
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-citybikes)
- **Trains in Europe**: timetables, departures and journeys, mostly in Germany and nearby countries.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-db-rail)
- **Transport networks**: find the timetable data (GTFS) of a bus, metro or tram network.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-mobility-database)

## Weather
- **Weather (Open-Meteo)**: current weather, forecasts and history, anywhere. No key.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-weather)

## Energy and economy
- **ODRE**: electricity and gas production and consumption in France (RTE, GRDF, Enedis…).
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-data-odre)
- **Electricity prices in Europe**: market prices, generation by source, renewable share.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-energy-charts)
- **data.economie.gouv.fr**: data of the French Ministry of the Economy, including fuel prices.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-data-economie-fr)

## News and RSS feeds
- **RSS**: read any RSS or Atom feed. No key.
  [▶ Install](?connector=https://github.com/mrfentmen/rss-mcp)
- **RSS feeds (filters)**: read, filter and search several feeds.
  [▶ Install](?connector=https://github.com/aashish/rss-mcp-server)

## Banking
- **Qonto (official)**: accounts, transactions, invoices and transfers of your business account. Online
  service: once added, click **Sign in** in Settings → Connectors. Sensitive operations are approved in the
  Qonto app. [▶ Install](?connector=https://mcp.qonto.com/mcp&name=qonto)

## Films, series, TV and cinema
- **TMDB (films and series)**: search a film or a series, ratings, recommendations, and **where to watch
  it** (Netflix, Prime Video, Disney+, Canal+…): "what is new on Netflix?". Needs a free themoviedb.org
  token (*Read Access Token*). Set `TMDB_REGION` to your country (`FR`, `GB`…) and `TMDB_LANGUAGE`
  (`en-GB`…). [▶ Install](?connector=https://github.com/Grinv/tmdb-mcp)
- **Series and TV schedule (TVmaze)**: search a series, and a day's TV schedule by country. No key. For
  France the schedule is partial (mostly series).
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-movies)
- **Allociné**: films now showing, cinemas near a town or a postcode, showtimes (dubbed, original version,
  3D, IMAX), including Pathé, Gaumont and UGC cinemas. It reads the Allociné website without an official
  agreement: it may stop working. [▶ Install](?connector=https://github.com/Racimy/Allocin-MCP)

## Online services (SaaS)
The official services below connect with your account: once added, click **Sign in** in Settings → Connectors.
The assistant only gets what you allow.

- **Notion (official)**: search, read and write your pages and databases.
  [▶ Install](?connector=https://mcp.notion.com/mcp&name=notion)
- **Linear (official)**: issues, projects and cycles.
  [▶ Install](?connector=https://mcp.linear.app/mcp&name=linear)
- **monday.com (official)**: boards, items and updates.
  [▶ Install](?connector=https://mcp.monday.com/mcp&name=monday)
- **Airtable (official)**: your bases, tables and records.
  [▶ Install](?connector=https://mcp.airtable.com/mcp&name=airtable)
- **Todoist (official)**: your tasks and projects.
  [▶ Install](?connector=https://ai.todoist.net/mcp&name=todoist)
- **Miro (official)**: read and create on your whiteboards.
  [▶ Install](?connector=https://mcp.miro.com/&name=miro)
- **Canva (official)**: search and create designs.
  [▶ Install](?connector=https://mcp.canva.com/mcp&name=canva)
- **Gamma (official)**: create presentations and documents.
  [▶ Install](?connector=https://mcp.gamma.app/mcp&name=gamma)
- **Calendly (official)**: your event types, free slots and bookings.
  [▶ Install](?connector=https://mcp.calendly.com/&name=calendly)
- **Trello**: boards, lists and cards. Needs a Trello key and token (trello.com/power-ups/admin).
  [▶ Install](?connector=https://github.com/endlesshoper/trello-mcp)
- **Stripe (official)**: payments, customers, invoices, subscriptions.
  [▶ Install](?connector=https://mcp.stripe.com/&name=stripe)
- **PayPal (official)**: invoices, orders, disputes and transactions.
  [▶ Install](?connector=https://mcp.paypal.com/mcp&name=paypal)
- **Wix (official)**: manage your Wix site (pages, shop, bookings).
  [▶ Install](?connector=https://mcp.wix.com/mcp&name=wix)
- **Hugging Face (official)**: search models, datasets and papers. No account.
  [▶ Install](?connector=https://hf.co/mcp&name=huggingface)
- **Wolfram (official)**: calculations, unit conversions, scientific and everyday data.
  [▶ Install](?connector=https://agenttools.wolfram.com/mcp&name=wolfram)
- **Zapier and Make**: connect thousands of apps (Google Drive, Dropbox, Gmail, Trello…). The address is
  specific to your account: create your server at mcp.zapier.com (or in Make), copy its address, then add it in
  Settings → Connectors. No direct link here.

## Hosting and cloud

- **OVHcloud (official, beta)**: manage your servers, instances, storage, network, databases and account. You sign
  in with your OVHcloud account; its permissions (IAM) decide what the assistant may do. Address for Europe; for
  another region, replace `eu` in Settings → Connectors.
  [▶ Install](?connector=https://mcp.eu.ovhcloud.com/mcp&name=ovhcloud)
- **Hostinger (official)**: websites, VPS, domains and DNS, mailboxes, subscriptions. Needs an API key (hPanel →
  Profile → API), to put in `HOSTINGER_API_TOKEN`.
  [▶ Install](?connector=https://github.com/hostinger/api-mcp-server)
- **Scaleway, Infomaniak**: no official connector, only community projects, not checked here.
- **o2switch**: no known connector.

## Travel and outings
- **Ferryhopper (official)**: ferry timetables and prices in Europe (Greece, Italy, Corsica…). No account.
  [▶ Install](?connector=https://mcp.ferryhopper.com/mcp&name=ferryhopper)
- **Peek (official)**: activities, tours and excursions to book. No account.
  [▶ Install](?connector=https://mcp.peek.com/&name=peek)

## Jobs
- **France Travail (formerly Pôle emploi)**: job offers (including temp agencies' offers), job descriptions
  (ROME) and, optionally, companies that are hiring (La Bonne Boîte). Uses the official APIs: create a free
  account at francetravail.io, subscribe an application to the *Offres d'emploi* API, then fill in
  `FT_CLIENT_ID` and `FT_CLIENT_SECRET`. [▶ Install](?connector=https://github.com/jojolabagarre/france-travail-mcp)
- **Jobs in Europe (Arbeitnow)**: job offers, mostly in Germany and Europe. No key.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-arbeitnow)
- **Remote jobs (Remotive)**: remote job offers, worldwide. No key.
  [▶ Install](?connector=https://github.com/pipeworx-io/mcp-remotive)

## Groceries and delivery (experimental)
These services have no public API. These connectors pretend to be a web browser and may stop working at any
time. Use an account you are willing to risk.

- **Uber Eats**: search restaurants, read menus, order. It drives a web browser, which Talixio
  downloads when installing it (about 100 MB, once).
  [▶ Install](?connector=https://github.com/markswendsen-code/mcp-ubereats)
- **E.Leclerc Drive**: search products, fill the basket. Needs Node.js 22 or newer.
  [▶ Install](?connector=https://github.com/skunkobi/mcp-leclerc-drive)
- **Swiss supermarkets**: Migros, Coop, Aldi, Denner, Lidl Switzerland…: products, prices and offers.
  [▶ Install](?connector=https://github.com/nicktcode/swissgroceries-mcp)

## Not available yet
- **Carrefour**: the existing connectors use a real web browser to get around the site's protection.
  Talixio cannot install them.
- **Uber (rides)**: Uber has an ordinary web API, for partners only, but no MCP server.
- **McDonald's**: an official service exists in China only (mcp.mcd.cn, with a personal token).
  Nothing for France.
- **Lidl France, Intermarché, Auchan, Deliveroo**: nothing usable found.
- **Full listings of French TV channels**: no connector. TVmaze, above, gives part of it. The assistant can
  also read a TV guide page on the web, or a TV listings RSS feed with the RSS connector.
- **Fnac, Darty, Cdiscount (online shopping)**: nothing usable. For Fnac, only a paid service (Apify).
- **Pathé, Gaumont, UGC cinemas**: no official connector; Allociné, above, gives their showtimes.
- **EDF, Engie, GRDF, TotalEnergies**: no connector. An Enedis Linky connector (your own consumption)
  exists, written in Go: Talixio cannot install it yet. ODRE, above, gives the national figures.
- **SeLoger, PAP, Leboncoin (property)**: nothing for SeLoger and PAP. The Leboncoin connectors are written
  in Python and blocked by the site's protection. DVF, above, gives actual sale prices.
- **Cars (Aramisauto, La Centrale, Vendezvotrevoiture, L'argus)**: nothing installable. A CarHunt service
  (Leboncoin, La Centrale listings…) is announced, but could not be checked.
- **RATP, Île-de-France Mobilités, RTM and other networks**: an Île-de-France Mobilités connector exists
  (Python, with a PRIM key); Talixio cannot install it yet. Nothing for RTM or other networks: *Transport
  networks*, above, finds their published timetables.
- **Google Maps, Waze**: nothing for Waze. The Google Maps connectors need a Google Cloud key (billed beyond
  a free quota); use the OpenStreetMap ones above instead.
- **Travel agencies, Airbnb, Booking, flights**: no agency. The Airbnb connector found follows the site's
  rules, which block it. The flight connectors need a paid key.
- **Tourist offices, town halls, museums**: no dedicated connector in France. Their data often goes through
  data.gouv.fr, Opendatasoft or the city connectors; Overpass finds museums around you. A Louvre connector
  exists, but could not be checked.
- **Radio France** (France Inter, franceinfo, France Culture, FIP…): the official API gives schedules and
  podcasts, with a free key. A community connector exists, but it could not be checked.
- **Amazon (shopping)**: only a paid service (HasData). For Prime Video, see TMDB.
- **Other banks and aggregators** (Powens, Bridge, Linxo, Nickel, Shine, high-street banks): no official
  connector. BankMCP reads your accounts at almost any European bank, read-only, through open banking
  (Enable Banking), but it needs Node.js 24, which the standard installation does not have.
- **Google Drive, Dropbox, Box**: Google has an official Drive server, but you must create your own app in
  Google Cloud. Dropbox's only accepts some apps. Box also needs an app. The simplest: go through Zapier or
  Make, above.
- **Asana, Jira and Confluence (Atlassian), Slack, ClickUp, Figma, GitHub**: their official servers need you to
  create an app there first (client id). Talixio can use it (the connector's `oauth` setting), but it is for
  experienced users.
- **LinkedIn**: no official connector. The community ones read the site with your session, against its terms
  of use, and may get your account blocked.
- **Indeed**: the official server is reserved for Claude.
- **APEC, Cadremploi, Hellowork, Welcome to the Jungle, temp agencies (Adecco, Manpower, Randstad…)**: nothing
  available. Many of their offers are also published on France Travail, above.
- **Légifrance**: no online connector found. EUR-Lex covers European law.
- **Python servers with dependencies** cannot be installed yet.

One is missing? Ask the assistant to look for an MCP server for that service, then give it the GitHub
address it found. See also *Connectors / Installing a connector*.
