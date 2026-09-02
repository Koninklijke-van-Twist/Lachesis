<?php

/**
 * Constants
 */

const VOORTGANG_CACHE_VERSION = 2;
/** OData-paginaformaat: minder roundtrips bij grote sets. */
const VOORTGANG_ODATA_PAGE_SIZE = 1000;

const VOORTGANG_COMPANIES = [
    'Koninklijke van Twist',
    'Hunter van Twist',
    'KVT Gas',
];

const VOORTGANG_WORKORDERS_ENTITY = 'AppWerkorders';
const VOORTGANG_CONTRACTS_ENTITY = 'Onderhoudscontract';

const VOORTGANG_WORKORDERS_SELECT = 'No,Contract_No,Status,Task_Code,Start_Date';
const VOORTGANG_CONTRACTS_SELECT = 'Contract_No,Description,Invoice_Period,KVT_Memo_Internal_Use_Only,KVT_Total_Sales_Price,KVT_Total_Revenue,KVT_Total_Cost';

/** Kolomvolgorde van status-totalen. */
const VOORTGANG_STATUSES = [
    'Afgesloten',
    'Geannuleerd',
    'Gecontroleerd',
    'Gefactureerd',
    'Gepland',
    'Onderhanden',
    'Ondertekend',
    'Open',
    'Uitgevoerd',
];

/** Voortgang = (deze statussen) / totaal werkorders. */
const VOORTGANG_PROGRESS_STATUSES = [
    'Gecontroleerd',
    'Geannuleerd',
];

/** Taakcode die optioneel verborgen kan worden via settings. */
const VOORTGANG_HIDDEN_TASK_CODE_PD = 'PD';
