<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customer Policy Documents
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the policies a customer must accept before
    | paying. The `version` is recorded server-side against each consent for
    | the audit trail (never trust a client-supplied version). Bump a version
    | whenever the corresponding PDF in public/docs is replaced.
    |
    */

    'terms' => [
        'title' => 'Terms & Conditions',
        'url' => '/docs/Terms-Conditions.docx.pdf',
        'version' => '2026-06-01',
    ],

    'refund' => [
        'title' => 'Refund Policy',
        'url' => '/docs/Refund-Policy.docx.pdf',
        'version' => '2026-06-01',
    ],

    'privacy' => [
        'title' => 'Privacy Policy',
        'url' => '/docs/Privacy-Policy.docx.pdf',
        'version' => '2026-06-01',
    ],

];
