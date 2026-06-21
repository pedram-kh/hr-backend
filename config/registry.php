<?php

return [
    // Path to the master index spreadsheet imported by `registry:import`.
    // Only 01_listado_convenios.xlsx is a registry; CONVENIOS 2026.xls is a
    // human status note (no numbers/structure) and is NOT imported (Sprint 1).
    'xlsx_path' => env('REGISTRY_XLSX_PATH', base_path('data/01_listado_convenios.xlsx')),

    // The sheet inside the workbook that holds the registry rows.
    'sheet' => env('REGISTRY_SHEET', 'LABOUR AGREEMENTS'),
];
