<?php

return [

    // PRIVATE disk for rendered invoice PDFs. Must NOT be publicly served — the
    // buyer downloads through the access-controlled /invoices/{uuid}/download
    // endpoint, never a direct storage URL.
    'disk' => env('INVOICE_DISK', 'local'),

];
