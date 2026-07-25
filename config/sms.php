<?php

return [

    // 'log' (default) writes the message to the log; 'smschef' sends real SMS.
    'driver' => env('SMS_DRIVER', 'log'),

    'smschef' => [
        'endpoint' => env('SMSCHEF_ENDPOINT', 'https://www.cloud.smschef.com/api/send/sms'),
        'secret' => env('SMSCHEF_SECRET'),
        'device' => env('SMSCHEF_DEVICE'),
    ],

];
