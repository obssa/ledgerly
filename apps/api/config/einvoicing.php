<?php

/*
| Tax-authority e-invoicing per country. Endpoints come from each authority's official technical
| specification and are set per environment; nothing here is guessed.
*/

return [
    // true in local/dev/test: every company clears through the SandboxConnector.
    'force_sandbox' => env('EINVOICE_FORCE_SANDBOX', true),

    'countries' => [
        'ET' => [
            'authority' => 'ET_MOR',
            'connector' => 'ethiopia_mor',
            'base_url' => env('EINVOICE_ET_BASE_URL'),
        ],
        'KE' => [
            'authority' => 'KE_KRA',
            'connector' => 'kenya_etims',
            'base_url' => env('EINVOICE_KE_BASE_URL'),
            'sales_endpoint' => env('EINVOICE_KE_SALES_ENDPOINT'),
        ],
        // 'UG' => ['authority' => 'UG_URA', 'connector' => 'uganda_efris'],   phase 2
        // 'TZ' => ['authority' => 'TZ_TRA', 'connector' => 'tanzania_vfd'],   phase 2
        // 'RW' => ['authority' => 'RW_RRA', 'connector' => 'rwanda_ebm'],     phase 2
        // 'NG' => ['authority' => 'NG_NRS', 'connector' => 'nigeria_mbs'],    phase 3
    ],
];
