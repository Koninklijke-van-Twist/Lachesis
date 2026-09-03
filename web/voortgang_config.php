<?php

/**
 * Constants
 */

const VOORTGANG_CACHE_VERSION = 3;
/** OData-paginaformaat: minder roundtrips bij grote sets. */
const VOORTGANG_ODATA_PAGE_SIZE = 1000;

const VOORTGANG_COMPANIES = [
    'Koninklijke van Twist',
    'Hunter van Twist',
    'KVT Gas',
];

const VOORTGANG_WORKORDERS_ENTITY = 'AppWerkorders';
const VOORTGANG_CONTRACTS_ENTITY = 'Onderhoudscontract';
const VOORTGANG_PROFORMA_ENTITY = 'SalesInvoiceSubform';

const VOORTGANG_WORKORDERS_SELECT = 'No,Contract_No,Status,Task_Code,Start_Date';
const VOORTGANG_CONTRACTS_SELECT = 'Contract_No,Description,Invoice_Period,KVT_Memo_Internal_Use_Only,KVT_Total_Sales_Price,KVT_Total_Revenue,KVT_Total_Cost';
const VOORTGANG_PROFORMA_SELECT = 'Job_Task_No,Line_Amount,Document_Type,Document_No';
const VOORTGANG_PROFORMA_DOCUMENT_TYPE = 'Factuur';

/** BC webclient-pagina's voor deep links. 11333028 = Werkorderkaart (job task), 43 = Verkoopfactuur. */
const VOORTGANG_BC_PAGE_WORKORDER = 11333028;
const VOORTGANG_BC_PAGE_SALES_INVOICE = 43;

/** Kolomvolgorde van status-totalen. */
const VOORTGANG_STATUSES = [
    'Open',
    'Gepland',
    'Onderhanden',
    'Uitgevoerd',
    'Ondertekend',
    'Gecontroleerd',
    'Gefactureerd',
    'Geannuleerd',
    'Afgesloten',
];

/** Voortgang = (deze statussen) / totaal werkorders. */
const VOORTGANG_PROGRESS_STATUSES = [
    'Gecontroleerd',
    'Geannuleerd',
];

/** Taakcode die optioneel verborgen kan worden via settings. */
const VOORTGANG_HIDDEN_TASK_CODE_PD = 'PD';
