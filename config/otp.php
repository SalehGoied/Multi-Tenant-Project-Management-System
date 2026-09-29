<?php

return [

    /*
    |--------------------------------------------------------------------------
    | One-time password lifetime
    |--------------------------------------------------------------------------
    |
    | Minutes before a newly issued code expires. Verification rejects a code
    | once this window has passed.
    |
    */

    'ttl' => (int) env('OTP_TTL', 5),

];
