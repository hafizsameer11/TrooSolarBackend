<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Guarantor form PDF paths (by BNPL flow)
    |--------------------------------------------------------------------------
    | Paths relative to public/. Admin uploads one PDF per flow (Residential / SME).
    | Customers download the form matching their application customer_type.
    | Commercial applications use the SME form.
    |
    | Legacy single path (GUARANTOR_FORM_PATH) is kept as a Residential fallback
    | when the residential-specific file has not been uploaded yet.
    */
    'guarantor_form_path' => env('GUARANTOR_FORM_PATH', 'documents/guarantor-form.pdf'),

    'guarantor_form_paths' => [
        'residential' => env('GUARANTOR_FORM_PATH_RESIDENTIAL', 'documents/guarantor-form-residential.pdf'),
        'sme' => env('GUARANTOR_FORM_PATH_SME', 'documents/guarantor-form-sme.pdf'),
    ],
];
