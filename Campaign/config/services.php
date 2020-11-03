<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Stripe, Mailgun, SparkPost and others. This file provides a sane
    | default location for this type of information, allowing packages
    | to have a conventional place to find your various credentials.
    |
    */
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
    ],

    'maxmind' => [
        'database' => base_path(env('MAXMIND_DATABASE')),
    ],

    'ses' => [
        'key' => env('SES_KEY'),
        'secret' => env('SES_SECRET'),
        'region' => 'us-east-1',
    ],

    'sparkpost' => [
        'secret' => env('SPARKPOST_SECRET'),
    ],

    'stripe' => [
        'model' => App\User::class,
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
    ],

    'crm_segment' => [
        'base_url' => env('CRM_SEGMENT_API_ADDR'),
        'token' => env('CRM_SEGMENT_API_TOKEN'),
    ],

    'remp' => [
        'beam' => [
            'web_addr' => env('REMP_BEAM_ADDR'),
            'segments_addr' => env('REMP_SEGMENTS_ADDR'),
        ],
        'mailer' => [
            'web_addr' => env('REMP_MAILER_ADDR'),
        ],
        'sso' => [
            'web_addr' => env('REMP_SSO_ADDR'),
            'api_token' => env('REMP_SSO_API_TOKEN')
        ],
        'pythia' => [
            'segments_addr' => env('REMP_PYTHIA_SEGMENTS_ADDR'),
        ],
        'linked' => [
            'beam' => [
                'url' => env('REMP_BEAM_ADDR'),
                'icon' => 'album',
            ],
            'campaign' => [
                'url' => '/',
                'icon' => 'trending-up',
            ],
            'mailer' => [
                'url' => env('REMP_MAILER_ADDR'),
                'icon' => 'email',
            ],
        ],
    ],
];
