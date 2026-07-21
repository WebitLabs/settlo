<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cron Endpoint Secret
    |--------------------------------------------------------------------------
    |
    | Shared secret required by the HTTP cron endpoints (/cron/{command}) that
    | let an external pinger run scheduled commands on serverless deploys where
    | no schedule:run daemon exists. When unset, the endpoints respond 503 and
    | nothing can be triggered over HTTP — the safe default for local dev.
    |
    */

    'secret' => env('CRON_SECRET'),

];
