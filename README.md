# Lachesis

Contractvoortgang (onderhoudscontracten / werkorders / proforma) uit Business Central op sleutels.kvt.nl/lachesis.

## Structuur

- `web/index.php` — UI (cache-driven)
- `web/voortgang_data.php` — snapshot-build + contract-refresh
- `web/odata.php` — OData-client, lokale filecache-widget, optionele Mímir-proxy
- `web/nightly.php` — BC-refresh per bedrijf (CLI of GET)
- `web/hourly.php` — momenteel no-op (voortgang via nightly)
- `web/auth.php` — credentials (niet in git)

## Mímir (optioneel)

Zet in `web/auth.php` (niet in git):

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

Met `$mimirApi` gezet proberen company-discovery en alle OData-fetches (`voortgang_paginate_entity` / `bc_fetch_rows` / `odata_get_all`, ook `nightly.php` via CLI of GET) eerst Mímir. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Lachesis dezelfde data op via het oude directe Business Central-pad (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-verzoek over. Laat die BC-credentials in `auth.php` naast `$mimirApi` staan; ontbreken ze, dan komt de oorspronkelijke Mímir-fout terug. Zonder `$mimirApi` blijft het bestaande directe BC-pad ongewijzigd.

**max_age-beleid**

| Soort fetch | `max_age` naar Mímir |
| --- | --- |
| `nightly.php` | **14400** (`LACHESIS_NIGHTLY_MAX_AGE`, 4u) |
| `hourly.php` | doet géén BC-OData vandaag — constant `LACHESIS_HOURLY_MAX_AGE` (**1800**, ≤30 min) gereserveerd |
| UI / on-demand / contract-refresh | bestaande TTL **3600** (`LACHESIS_ODATA_TTL` / `bc_fetch_rows`-default) |

Tim moet `$mimirApi` (en optioneel `$mimirBase`) én de BC-credentials (`$auth_list`, `$environment`, `$auth`, `$baseUrl`) lokaal/op de server in `auth.php` zetten; `auth.php` wordt niet gecommit. Zie [Mímir Implementatie](https://wiki.kvt.nl/books/mimir/page/implementatie).

## auth.php

Geen `auth.php` in deze repository (staat in `.gitignore`). Lokaal/op de server de Mímir-sleutel zetten zoals hierboven, met de BC-credentials ernaast — die blijven nodig als Mímir uitvalt (web én `nightly.php`). Zie `web/auth_TEMPLATE.php`.

## Data

`nightly.php` haalt per bedrijf werkorders, onderhoudscontracten en proforma-bedragen op en schrijft de voortgang-cache. `hourly.php` skipped (voortgang vernieuwt via nightly). UI leest uit cache; contract-refresh (`refresh_contract.php`) haalt één contract live op.

## Starten

De applicatie draait vanuit `web/` via `index.php`. Roep `nightly.php` aan om de cache te vullen of te verversen.
