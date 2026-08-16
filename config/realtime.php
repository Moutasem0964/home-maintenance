<?php

return [

    // 'log' (default, local/tests) keeps Laravel out of Firebase; 'firebase' mints real
    // custom tokens and writes the location membership node to the Realtime Database.
    'driver' => env('REALTIME_DRIVER', 'log'),

];
