<?php

return [


    /*
    |--------------------------------------------------------------------------
    | Creds
    |--------------------------------------------------------------------------
    |
    |
    |
    */

    'heartland_dev_cert'                  => env('heartland_dev_crtstr', ''),
    'heartland_dev_termid'                => env('heartland_dev_termid', ''),
    'heartland_dev_auth'                  => env('heartland_dev_auth', ''),
    'heartland_dev_url'                   => env('heartland_dev_hppurl',""),
    'heartland_dev_dtc'                   => env('heartland_dev_dtc_id',""),
    'heartland_dev_protect_payurl'        => env('heartlnad_dev_protectpay_url',"https://xmltestapi.propay.com/protectpay/Payers/"),
    'heartland_dev_propay_payurl'         => env('heartland_dev_propay_url',"https://xmltest.propay.com/API/PropayAPI.aspx"),


    'heartland_live_cert'               => env('heartland_live_crtstr', ''),
    'heartland_live_termid'             => env('heartland_live_termid', ''),
    'heartland_live_auth'               => env('heartland_live_auth', ''),
    'heartland_live_url'                => env('heartland_live_hppurl',""),
    'heartland_live_dtc'                => env('heartland_live_dtc_id',""),
    'heartland_live_protect_payurl'     => env('heartlnad_live_protectpay_url',"https://api.propay.com/protectpay/Payers/"),
    'heartland_live_propay_payurl'      => env('heartland_live_propay_url',"https://epay.propay.com/api/propayapi.aspx"),



  ];
