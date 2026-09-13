<?php

return [
    'name' => env('COMPANY_NAME', 'NavlunIQ'),
    'tax_office' => env('COMPANY_TAX_OFFICE'),
    'tax_no' => env('COMPANY_TAX_NO'),
    'mersis_no' => env('COMPANY_MERSIS_NO'),
    'address' => env('COMPANY_ADDRESS'),
    'phone' => env('COMPANY_PHONE'),
    'email' => env('COMPANY_EMAIL', env('MAIL_FROM_ADDRESS')),
    'legal_document_version' => env('LEGAL_DOCUMENT_VERSION', '1.0'),
    'legal_effective_date' => env('LEGAL_EFFECTIVE_DATE'),
];
