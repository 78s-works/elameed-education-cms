<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo data
    |--------------------------------------------------------------------------
    |
    | The demo academies (farag-physics, ahmedtammam) exist so developers and
    | testers have a full dataset to work against. Production stays clean and
    | real: outside `production` they seed as usual, on production only an
    | explicit SEED_DEMO=true lets them through.
    |
    */

    'demo' => filter_var(env('SEED_DEMO', false), FILTER_VALIDATE_BOOL),

];
