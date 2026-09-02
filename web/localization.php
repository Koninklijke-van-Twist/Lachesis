<?php

/**
 * Constants
 */

const FLAG_SVGS = [
    'nl' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><rect width="900" height="600" fill="#AE1C28"/><rect width="900" height="400" fill="#fff"/><rect width="900" height="200" fill="#fff"/><rect width="900" height="200" y="0" fill="#AE1C28"/><rect width="900" height="200" y="200" fill="#fff"/><rect width="900" height="200" y="400" fill="#21468B"/></svg>',
    'en' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 40"><clipPath id="a"><path d="M0 0v40h60V0z"/></clipPath><clipPath id="b"><path d="M30 20h30v20zv20H0zH0V0zV0h30z"/></clipPath><g clip-path="url(#a)"><path d="M0 0v40h60V0z" fill="#012169"/><path d="M0 0l60 40m0-40L0 40" stroke="#fff" stroke-width="8"/><path d="M0 0l60 40m0-40L0 40" clip-path="url(#b)" stroke="#C8102E" stroke-width="5"/><path d="M30 0v40M0 20h60" stroke="#fff" stroke-width="13"/><path d="M30 0v40M0 20h60" stroke="#C8102E" stroke-width="8"/></g></svg>',
    'de' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 5 3"><rect width="5" height="3" y="0" fill="#000"/><rect width="5" height="2" y="1" fill="#D00"/><rect width="5" height="1" y="2" fill="#FFCE00"/></svg>',
    'fr' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 600"><rect width="900" height="600" fill="#ED2939"/><rect width="600" height="600" fill="#fff"/><rect width="300" height="600" fill="#002395"/></svg>',
];

const SUPPORTED_LANGUAGES = [
    'nl' => ['flag' => '🇳🇱', 'label' => 'Nederlands'],
    'en' => ['flag' => '🇬🇧', 'label' => 'English'],
    'de' => ['flag' => '🇩🇪', 'label' => 'Deutsch'],
    'fr' => ['flag' => '🇫🇷', 'label' => 'Français'],
];

const LOCALE_BY_LANG = [
    'nl' => 'nl-NL',
    'en' => 'en-GB',
    'de' => 'de-DE',
    'fr' => 'fr-FR',
];

const TRANSLATIONS = [
    'nl' => [
        'lang.menu_aria' => 'Taal kiezen',
        'lang.switch_to' => 'Schakel naar %s',
        'app.title' => 'Lachesis - Contractvoortgang',
        'voortgang.hero.title' => 'Contractvoortgang',
        'voortgang.hero.subtitle' => 'Werkorders per onderhoudscontract, gesorteerd op voortgang.',
        'voortgang.label.company' => 'Bedrijf',
        'voortgang.label.search' => 'Zoeken',
        'voortgang.label.page_size' => 'Regels per pagina',
        'voortgang.label.progress_filter' => 'Voortgangsfilter',
        'voortgang.filter.all' => 'Alles',
        'voortgang.filter.completed' => 'Voltooid',
        'voortgang.filter.incomplete' => 'Onvoltooid',
        'voortgang.placeholder.search' => 'Zoek op contract, omschrijving of instructies',
        'voortgang.btn.excel' => 'Excel',
        'voortgang.pager.prev' => 'Vorige',
        'voortgang.pager.next' => 'Volgende',
        'voortgang.pager.pages' => 'Pagina’s',
        'voortgang.pager.status' => 'Pagina %1$d van %2$d · %3$d regels',
        'voortgang.col.contract_no' => 'Contractnummer',
        'voortgang.col.description' => 'Omschrijving',
        'voortgang.col.invoice_period' => 'Invoice period',
        'voortgang.col.total' => 'Totaal',
        'voortgang.col.progress' => '% Gecont.+Geann.',
        'voortgang.col.original_amount' => 'Origineel bedrag',
        'voortgang.col.invoiced_amount' => 'Gefactureerd bedrag',
        'voortgang.col.total_cost' => 'Totale Kosten',
        'voortgang.col.open_proforma' => 'Openstaande proforma',
        'voortgang.col.instructions' => 'Instructies',
        'voortgang.col.workorder_no' => 'Werkorder',
        'voortgang.col.status' => 'Status',
        'voortgang.cached_at' => 'Laatst bijgewerkt: %s',
        'voortgang.row_count' => '%d contracten',
        'voortgang.empty.cache' => 'Nog geen nachtelijke data. Start nightly.php om werkorders en contracten op te halen.',
        'voortgang.empty.rows' => 'Geen contracten met werkorders gevonden.',
        'voortgang.stale.cache' => 'De nachtelijke update is verouderd. Start nightly.php opnieuw.',
        'voortgang.export.failed' => 'Excel-export mislukt.',
        'voortgang.modal.title' => 'Werkorders',
        'voortgang.modal.close' => 'Sluiten',
        'voortgang.modal.empty' => 'Geen werkorders op deze status.',
        'voortgang.company_pick.title' => 'Kies een bedrijf',
        'voortgang.company_pick.body' => 'Selecteer het bedrijf waarvan je de contractvoortgang wilt bekijken.',
        'voortgang.company_welcome.title' => 'Bedrijf opgeslagen',
        'voortgang.company_welcome.body' => 'Je keuze is opgeslagen. Wil je later een ander bedrijf bekijken? Rechtsboven op de pagina kun je via de dropdown een ander bedrijf kiezen.',
    ],

    'en' => [
        'lang.menu_aria' => 'Choose language',
        'lang.switch_to' => 'Switch to %s',
        'app.title' => 'Contract progress',
        'voortgang.hero.title' => 'Contract progress',
        'voortgang.hero.subtitle' => 'Work orders per maintenance contract, sorted by progress.',
        'voortgang.label.company' => 'Company',
        'voortgang.label.search' => 'Search',
        'voortgang.label.page_size' => 'Rows per page',
        'voortgang.label.progress_filter' => 'Progress filter',
        'voortgang.filter.all' => 'All',
        'voortgang.filter.completed' => 'Completed',
        'voortgang.filter.incomplete' => 'Incomplete',
        'voortgang.placeholder.search' => 'Search contract, description or instructions',
        'voortgang.btn.excel' => 'Excel',
        'voortgang.pager.prev' => 'Previous',
        'voortgang.pager.next' => 'Next',
        'voortgang.pager.pages' => 'Pages',
        'voortgang.pager.status' => 'Page %1$d of %2$d · %3$d rows',
        'voortgang.col.contract_no' => 'Contract no.',
        'voortgang.col.description' => 'Description',
        'voortgang.col.invoice_period' => 'Invoice period',
        'voortgang.col.total' => 'Total',
        'voortgang.col.progress' => '% Checked+Canc.',
        'voortgang.col.original_amount' => 'Original amount',
        'voortgang.col.invoiced_amount' => 'Invoiced amount',
        'voortgang.col.total_cost' => 'Total cost',
        'voortgang.col.open_proforma' => 'Open proforma',
        'voortgang.col.instructions' => 'Instructions',
        'voortgang.col.workorder_no' => 'Work order',
        'voortgang.col.status' => 'Status',
        'voortgang.cached_at' => 'Last updated: %s',
        'voortgang.row_count' => '%d contracts',
        'voortgang.empty.cache' => 'No nightly data yet. Run nightly.php to fetch work orders and contracts.',
        'voortgang.empty.rows' => 'No contracts with work orders found.',
        'voortgang.stale.cache' => 'The nightly update is stale. Run nightly.php again.',
        'voortgang.export.failed' => 'Excel export failed.',
        'voortgang.modal.title' => 'Work orders',
        'voortgang.modal.close' => 'Close',
        'voortgang.modal.empty' => 'No work orders in this status.',
        'voortgang.company_pick.title' => 'Choose a company',
        'voortgang.company_pick.body' => 'Select the company whose contract progress you want to view.',
        'voortgang.company_welcome.title' => 'Company saved',
        'voortgang.company_welcome.body' => 'Your choice has been saved. Want to switch later? Use the company dropdown at the top right of the page.',
    ],

    'de' => [
        'lang.menu_aria' => 'Sprache wählen',
        'lang.switch_to' => 'Wechseln zu %s',
        'app.title' => 'Vertragsfortschritt',
        'voortgang.hero.title' => 'Vertragsfortschritt',
        'voortgang.hero.subtitle' => 'Arbeitsaufträge je Wartungsvertrag, sortiert nach Fortschritt.',
        'voortgang.label.company' => 'Unternehmen',
        'voortgang.label.search' => 'Suchen',
        'voortgang.label.page_size' => 'Zeilen pro Seite',
        'voortgang.label.progress_filter' => 'Fortschrittsfilter',
        'voortgang.filter.all' => 'Alles',
        'voortgang.filter.completed' => 'Abgeschlossen',
        'voortgang.filter.incomplete' => 'Offen',
        'voortgang.placeholder.search' => 'Vertrag, Beschreibung oder Anweisungen suchen',
        'voortgang.btn.excel' => 'Excel',
        'voortgang.pager.prev' => 'Zurück',
        'voortgang.pager.next' => 'Weiter',
        'voortgang.pager.pages' => 'Seiten',
        'voortgang.pager.status' => 'Seite %1$d von %2$d · %3$d Zeilen',
        'voortgang.col.contract_no' => 'Vertragsnummer',
        'voortgang.col.description' => 'Beschreibung',
        'voortgang.col.invoice_period' => 'Invoice period',
        'voortgang.col.total' => 'Gesamt',
        'voortgang.col.progress' => '% Gepr.+Annul.',
        'voortgang.col.original_amount' => 'Ursprungsbetrag',
        'voortgang.col.invoiced_amount' => 'Fakturierter Betrag',
        'voortgang.col.total_cost' => 'Gesamtkosten',
        'voortgang.col.open_proforma' => 'Offene Proforma',
        'voortgang.col.instructions' => 'Anweisungen',
        'voortgang.col.workorder_no' => 'Arbeitsauftrag',
        'voortgang.col.status' => 'Status',
        'voortgang.cached_at' => 'Zuletzt aktualisiert: %s',
        'voortgang.row_count' => '%d Verträge',
        'voortgang.empty.cache' => 'Noch keine Nachtdaten. Starten Sie nightly.php, um Arbeitsaufträge und Verträge abzurufen.',
        'voortgang.empty.rows' => 'Keine Verträge mit Arbeitsaufträgen gefunden.',
        'voortgang.stale.cache' => 'Die Nachtaktualisierung ist veraltet. Starten Sie nightly.php erneut.',
        'voortgang.export.failed' => 'Excel-Export fehlgeschlagen.',
        'voortgang.modal.title' => 'Arbeitsaufträge',
        'voortgang.modal.close' => 'Schließen',
        'voortgang.modal.empty' => 'Keine Arbeitsaufträge in diesem Status.',
        'voortgang.company_pick.title' => 'Unternehmen wählen',
        'voortgang.company_pick.body' => 'Wählen Sie das Unternehmen, dessen Vertragsfortschritt Sie ansehen möchten.',
        'voortgang.company_welcome.title' => 'Unternehmen gespeichert',
        'voortgang.company_welcome.body' => 'Ihre Auswahl wurde gespeichert. Möchten Sie später wechseln? Oben rechts auf der Seite können Sie über das Dropdown ein anderes Unternehmen wählen.',
    ],

    'fr' => [
        'lang.menu_aria' => 'Choisir la langue',
        'lang.switch_to' => 'Passer en %s',
        'app.title' => 'Avancement des contrats',
        'voortgang.hero.title' => 'Avancement des contrats',
        'voortgang.hero.subtitle' => 'Ordres de travail par contrat de maintenance, triés par avancement.',
        'voortgang.label.company' => 'Société',
        'voortgang.label.search' => 'Rechercher',
        'voortgang.label.page_size' => 'Lignes par page',
        'voortgang.label.progress_filter' => 'Filtre d’avancement',
        'voortgang.filter.all' => 'Tout',
        'voortgang.filter.completed' => 'Terminé',
        'voortgang.filter.incomplete' => 'Non terminé',
        'voortgang.placeholder.search' => 'Rechercher contrat, description ou instructions',
        'voortgang.btn.excel' => 'Excel',
        'voortgang.pager.prev' => 'Précédent',
        'voortgang.pager.next' => 'Suivant',
        'voortgang.pager.pages' => 'Pages',
        'voortgang.pager.status' => 'Page %1$d sur %2$d · %3$d lignes',
        'voortgang.col.contract_no' => 'N° contrat',
        'voortgang.col.description' => 'Description',
        'voortgang.col.invoice_period' => 'Invoice period',
        'voortgang.col.total' => 'Total',
        'voortgang.col.progress' => '% Contr.+Annul.',
        'voortgang.col.original_amount' => 'Montant d’origine',
        'voortgang.col.invoiced_amount' => 'Montant facturé',
        'voortgang.col.total_cost' => 'Coût total',
        'voortgang.col.open_proforma' => 'Proforma ouverte',
        'voortgang.col.instructions' => 'Instructions',
        'voortgang.col.workorder_no' => 'Ordre de travail',
        'voortgang.col.status' => 'Statut',
        'voortgang.cached_at' => 'Dernière mise à jour : %s',
        'voortgang.row_count' => '%d contrats',
        'voortgang.empty.cache' => 'Pas encore de données nocturnes. Lancez nightly.php pour récupérer les ordres de travail et les contrats.',
        'voortgang.empty.rows' => 'Aucun contrat avec ordres de travail trouvé.',
        'voortgang.stale.cache' => 'La mise à jour nocturne est obsolète. Relancez nightly.php.',
        'voortgang.export.failed' => 'Export Excel échoué.',
        'voortgang.modal.title' => 'Ordres de travail',
        'voortgang.modal.close' => 'Fermer',
        'voortgang.modal.empty' => 'Aucun ordre de travail pour ce statut.',
        'voortgang.company_pick.title' => 'Choisir une société',
        'voortgang.company_pick.body' => 'Sélectionnez la société dont vous souhaitez consulter l’avancement des contrats.',
        'voortgang.company_welcome.title' => 'Société enregistrée',
        'voortgang.company_welcome.body' => 'Votre choix a été enregistré. Pour changer plus tard, utilisez le menu déroulant en haut à droite de la page.',
    ],
];
/**
 * Functies
 */

function getUserPrefsPath(string $email): ?string
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $dir = __DIR__ . '/data/user_prefs';
    $filename = preg_replace('/[^a-z0-9._\-]/', '_', $email) . '.json';
    return $dir . '/' . $filename;
}

function loadUserPrefs(string $email): array
{
    $path = getUserPrefsPath($email);
    if ($path === null || !is_file($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function saveUserPref(string $email, string $key, mixed $value): void
{
    $path = getUserPrefsPath($email);
    if ($path === null) {
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $prefs = loadUserPrefs($email);
    $prefs[$key] = $value;
    file_put_contents($path, json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function getCurrentLanguage(): string
{
    $lang = (string) ($_SESSION['lang'] ?? 'nl');
    return array_key_exists($lang, SUPPORTED_LANGUAGES) ? $lang : 'nl';
}

function getHtmlLang(): string
{
    return getCurrentLanguage();
}

function getDateLocale(): string
{
    $lang = getCurrentLanguage();
    return LOCALE_BY_LANG[$lang] ?? 'nl-NL';
}

/**
 * Geeft de vertaling voor $key in de actieve taal.
 * Extra $args worden via sprintf ingevoegd (voor %d, %s, etc.).
 */
function LOC(string $key, mixed ...$args): string
{
    $lang = getCurrentLanguage();
    $translations = TRANSLATIONS[$lang] ?? TRANSLATIONS['nl'];
    $string = $translations[$key] ?? (TRANSLATIONS['nl'][$key] ?? $key);

    return $args !== [] ? sprintf($string, ...$args) : $string;
}

function localizationFlagSvg(string $lang): string
{
    $svg = FLAG_SVGS[$lang] ?? '';
    if ($svg === '') {
        return '';
    }

    $safeLang = preg_replace('/[^a-z0-9]/', '', $lang) ?? $lang;
    return str_replace(
        ['id="a"', 'url(#a)', 'id="b"', 'url(#b)'],
        ['id="flag-' . $safeLang . '-a"', 'url(#flag-' . $safeLang . '-a)', 'id="flag-' . $safeLang . '-b"', 'url(#flag-' . $safeLang . '-b)'],
        $svg
    );
}

function localizationUrlWithLang(string $lang): string
{
    $params = $_GET;
    unset($params['lang']);
    $params['lang'] = $lang;
    $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
    $query = http_build_query($params);
    return $path . ($query !== '' ? '?' . $query : '');
}

function localizationJsTranslations(array $keys): string
{
    $payload = [];
    foreach ($keys as $key) {
        $payload[$key] = LOC($key);
    }

    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function renderLanguageSwitcherStyles(): void
{
    echo <<<'CSS'
<style>
.lang-switcher {
    position: fixed;
    top: 12px;
    right: 12px;
    z-index: 5000;
    font-family: inherit;
}
.lang-switcher-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 30px;
    padding: 0;
    border: 1px solid rgba(0, 82, 155, 0.25);
    border-radius: 6px;
    background: #ffffff;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.12);
    cursor: pointer;
}
.lang-switcher-toggle:hover {
    background: #f2f9ff;
}
.lang-switcher-toggle svg {
    width: 28px;
    height: auto;
    display: block;
    border-radius: 2px;
    overflow: hidden;
}
.lang-switcher-menu {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    min-width: 160px;
    margin: 0;
    padding: 6px;
    list-style: none;
    background: #ffffff;
    border: 1px solid #c9d7eb;
    border-radius: 10px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.18);
    display: none;
}
.lang-switcher.is-open .lang-switcher-menu {
    display: block;
}
.lang-switcher-item a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-radius: 8px;
    color: var(--kvt-text, #1f2937);
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
}
.lang-switcher-item a:hover {
    background: #edf7ff;
}
.lang-switcher-item.is-active a {
    background: #e6f4ff;
}
.lang-switcher-item svg {
    width: 24px;
    height: auto;
    flex-shrink: 0;
    border-radius: 2px;
    overflow: hidden;
}
@media print {
    .lang-switcher {
        display: none !important;
    }
}
</style>
CSS;
}

function renderLanguageSwitcher(): void
{
    $current = getCurrentLanguage();
    $menuAria = htmlspecialchars(LOC('lang.menu_aria'), ENT_QUOTES);

    echo '<div class="lang-switcher" data-lang-switcher>';
    echo '<button type="button" class="lang-switcher-toggle" aria-haspopup="true" aria-expanded="false" aria-label="' . $menuAria . '">';
    echo localizationFlagSvg($current);
    echo '</button>';
    echo '<ul class="lang-switcher-menu" role="menu">';

    foreach (SUPPORTED_LANGUAGES as $code => $meta) {
        if ($code === $current) {
            continue;
        }

        $label = (string) ($meta['label'] ?? $code);
        $href = htmlspecialchars(localizationUrlWithLang($code), ENT_QUOTES);
        $title = htmlspecialchars(LOC('lang.switch_to', $label), ENT_QUOTES);

        echo '<li class="lang-switcher-item" role="none">';
        echo '<a role="menuitem" href="' . $href . '" title="' . $title . '">';
        echo localizationFlagSvg($code);
        echo '<span>' . htmlspecialchars($label) . '</span>';
        echo '</a>';
        echo '</li>';
    }

    echo '</ul>';
    echo '</div>';
}

function renderLanguageSwitcherScript(): void
{
    echo <<<'JS'
<script>
(function () {
    document.querySelectorAll('[data-lang-switcher]').forEach(function (root) {
        var toggle = root.querySelector('.lang-switcher-toggle');
        if (!toggle) {
            return;
        }

        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            var isOpen = root.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        document.addEventListener('click', function () {
            root.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
        });

        root.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    });
})();
</script>
JS;
}

/**
 * Page load
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (!isset($_SESSION['lang'])) {
    $prefEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    if ($prefEmail !== '') {
        $savedPrefs = loadUserPrefs($prefEmail);
        if (isset($savedPrefs['lang']) && array_key_exists($savedPrefs['lang'], SUPPORTED_LANGUAGES)) {
            $_SESSION['lang'] = $savedPrefs['lang'];
        }
    }
}

if (!isset($_SESSION['lang']) || !array_key_exists((string) $_SESSION['lang'], SUPPORTED_LANGUAGES)) {
    $_SESSION['lang'] = 'nl';
}

if (isset($_GET['lang']) && array_key_exists($_GET['lang'], SUPPORTED_LANGUAGES)) {
    $requestedLang = (string) $_GET['lang'];
    $langChanged = $requestedLang !== getCurrentLanguage();
    $_SESSION['lang'] = $requestedLang;
    $prefEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    if ($prefEmail !== '' && $langChanged) {
        saveUserPref($prefEmail, 'lang', $requestedLang);
    }

    $isApiAction = isset($_GET['action']) && trim((string) $_GET['action']) !== '';
    if (!$isApiAction && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
        $params = $_GET;
        unset($params['lang']);
        $path = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
        $query = http_build_query($params);
        header('Location: ' . $path . ($query !== '' ? '?' . $query : ''));
        exit;
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
