# Aequitas

Inkoopprijsvergelijking op basis van `AppItemCard` en `Prijslijstregels` uit Business Central.

## Data

`nightly.php` haalt per bedrijf de actuele Prijslijstregels op. `hourly.php` vult AppItemCard bij in stappen (standaard max. 100, tuneerbaar): eerst backfill, daarna gaps voor nieuwe prijslijstartikelen en catchup op `Last_Date_Modified`. Alleen afwijkende bedragen en dubbele regels blijven in de item-cache; welk artikel al gecheckt is staat in `*.items_checked.json`.

## Starten

De applicatie draait vanuit `web/` via `index.php`. Roep `nightly.php` aan om de cache te vullen of te verversen.
