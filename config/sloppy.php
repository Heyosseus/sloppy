<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Turn Sloppy off without removing the package. Everything it registers is
    | still bound into the container; only its surfaces go away.
    |
    */

    'enabled' => env('SLOPPY_ENABLED', true),

];
