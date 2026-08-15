<?php

return [

    // 'log' (default) writes the push to the log; 'fcm' sends via Firebase Cloud Messaging.
    'driver' => env('PUSH_DRIVER', 'log'),

];
