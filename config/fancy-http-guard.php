<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Checked BEFORE the body is parsed, for every request. Secure defaults,
    | every one a setting; the names are the contract's (the Python guard and
    | holy-sheet's `limits` use the same ones). A bad key or value is refused
    | when the app boots.
    |
    | PHP parses multipart and urlencoded bodies itself before any PHP code
    | runs, governed by post_max_size; set post_max_size at or above your
    | largest route cap, and nginx client_max_body_size above that.
    |
    */
    'limits' => [
        'maxBodyBytes' => 1048576,       // 1 MiB
        'maxDepth' => 32,
        'maxListLength' => 10000,        // arrays AND objects
        'maxErrors' => 20,               // items in one 422
        'finiteNumbers' => true,         // NaN, Infinity, 1e400 refused with the field path
        'maxMessageLength' => 300,       // code points of a public message; at most 4000
        'workbook' => [                  // paired with holy-sheet's reader limits
            'maxRows' => 100000,
            'maxColumns' => 1000,
            'maxCells' => 100000,
        ],
    ],

    /*
    | Named profiles, merged over `limits`. `upload` (10 MiB) is built in.
    | Use one on a route: Route::post(...)->guard('upload').
    */
    'profiles' => [
        // 'bulk' => ['maxListLength' => 100000],
    ],

    /*
    | Per route NAME: a profile name or inline limits. A route's own ->guard()
    | wins over this.
    */
    'routes' => [
        // 'imports.store' => 'upload',
    ],

    /*
    | The request id, on every response and in every envelope, and in
    | Context for every log line. An inbound id is reused only when you trust
    | the proxy that sets it, and only if it matches [A-Za-z0-9._:-]{1,128}.
    */
    'request_id_header' => 'x-request-id',
    'trust_incoming_request_id' => false,
];
