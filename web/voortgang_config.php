<?php

/**
 * Constants
 */

const VOORTGANG_CACHE_VERSION = 1;
/** OData-paginaformaat: minder roundtrips bij grote sets. */
const VOORTGANG_ODATA_PAGE_SIZE = 1000;

const VOORTGANG_COMPANIES = [
    'Koninklijke van Twist',
    'Hunter van Twist',
    'KVT Gas',
];

const VOORTGANG_WORKORDERS_ENTITY = 'AppWerkorders';
const VOORTGANG_CONTRACTS_ENTITY = 'AppMaintenanceContracts';
const VOORTGANG_PLANNING_ENTITY = 'ContractPlanningsregels';

const VOORTGANG_WORKORDERS_SELECT = 'No,Contract_No,Status';
const VOORTGANG_CONTRACTS_SELECT = 'Contract_No,Description,KVT_Total_Sales_Price,KVT_Total_Revenue,KVT_Total_Cost';
const VOORTGANG_PLANNING_SELECT = 'Contract_No,Amount_Period,Invoice_Period_Start_Date,Invoice_Period_End_Date';

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
