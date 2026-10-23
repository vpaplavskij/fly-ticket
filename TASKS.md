Work on task strictly in order. Skip completed

task: [x] investigate and fix why information command isnt working in flycheaplatvia telegram bot.

task: [x] hardcoded translations in /src/Api/Telegram, need make table with translations, and refactor all translations 
with db usage. Also should be efficient arhitecture.

task: [x] in @render-commands.php there is require once.. how we could fix this for many scripts in project we use? after
investigation stop and give options in session

task: [x] remove src/Api/Telegram/lang/ — DB table is the single source of truth for translations, no file fallback. Seed files moved to src/Database/seed/.

task: [x] Optimization #1 + #2: (1) `index.php status` now uses a cheap COUNT(DISTINCT DATE(depart)) per route instead of pulling every row via getLowestPerDay(); (2) `jobs.php fetch` runs all six source scripts concurrently (proc_open, wait on all) instead of sequentially — cron cycle drops from sum-of-six to slowest-single-source.

task: [x] check on unused namespaces in project. Fix them. — Audited all src/ + tests/: no dead namespace declarations remain (the `FlyTicket` ns in jobs.php is intentional for its job functions; the namespace-free files are deliberate: bootstrap, Database, seed arrays). Also removed the one genuinely-unused method found: FlightRepository::getLowestPerDay() (a one-line alias for getCheapestPerDay, only called by a test) — test migrated to call getCheapestPerDay directly.

task: [x] Add RIX ⇄ PFO (Riga ⇄ Paphos, Cyprus) route in both directions — investigate which airlines fly it and scrape data from them. Ryanair is the only operator (FR3337/FR3336); AirBaltic & Finnair don't serve it. Wired into ryanair-api.php + flycheap bot menu. Also added a `search-routes` route-existence health check to the Ryanair fetcher as a cheap backup signal (same MCP server, so not a true outage backup — no independent Ryanair fare source exists without reverse-engineering their signed booking API).

task [x] Refactor telegram bot so now there have to be new menu at start, probably all possible flights now should be named as country flights
for example RIX LCA is now LATVIA CYPRUSS + airport code if this country has many airports.

task [x] Next menu when user click for example LATVIA CYPRUSS (PFO). For /today command we should list default rix -> cypruss pfo flights
and available flights like estonian to -> cyprus then lithuanian -> cyprus as alternative with cheapest flight.
for /nextweek same as today logic, try keep compact. when comes month and 3 month commands we should list all as it was,
but for the cheapest available have to be added country flag marking this as country. 
so for example i click 3 months and ask dec 5 that have cheapest flight from lithuanian airport so i see lithuanian flag near ticket(first comes flag then buy ticket link)

task [x] same as country that has more airports, if user choose cyprus (LCA) -> latvia that command list /today and /nextweek lists first an lCA -> Latvia airport then
as alternative list PFo -> latvia. when user asks with month or 3 months should be listed just as it is now LCA to -> latvia

task [x] make sure that parsing data from other airpots as i mentioned is correctlly implemented.. — Verified end-to-end: live AirBaltic API carries VNO→LCA (18/18 days), LCA→VNO (23/23), RIX⇄LCA; KUN routes empty because AirBaltic has no Kaunas→Cyprus service (expected, seasonal per AGENTS.md). PFO alternatives come from the Ryanair fetcher (KUN→PFO 9 priced days live; VNO→PFO none — Ryanair doesn't serve it). Render output confirmed: `render-commands.php flycheap` shows all three legs with flags + alternative-origin lines (e.g. Tallinn→Larnaca €245.99 🇪🇪). No code changes needed — parsing is correct as implemented.

task [x] remake data fetching is set to 4 time day 6 morning, 11morning, 16afternoon, 20 afternoon — crontab.txt fetch line changed from `0,15,30,45 * * * *` (every 15 min) to `0 6,11,16,20 * * *`.

task [x] clean up garbage code and propare project for shipping, also clean redundant methods, comments, simplify project

task [x] update @AGENT.MD and write and run some test, also bot tests that every command working.