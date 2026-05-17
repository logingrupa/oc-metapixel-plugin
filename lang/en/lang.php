<?php

return [
    'plugin' => [
        'name'        => 'Meta Pixel + Conversions API',
        'description' => 'Server-deduplicated Meta Pixel and Conversions API tracking via adapter pattern.',
    ],
    'settings' => [
        'label'       => 'Meta Pixel + CAPI',
        'description' => 'Configure the Pixel ID, CAPI access token, and Test Events code for Meta tracking.',
        'category'    => 'Marketing',
        'fields' => [
            'pixel_id_label'             => 'Pixel ID',
            'pixel_id_comment'           => 'Your Meta Pixel ID (digits-only). Acquire from Meta Events Manager > Data sources > Pixel > Settings.',
            'capi_access_token_label'    => 'CAPI Access Token',
            'capi_access_token_comment'  => 'Conversions API access token. Acquire from Meta Events Manager > Settings > Generate access token.',
            'test_event_code_label'      => 'Test Events Code',
            'test_event_code_comment'    => 'Optional. Routes events to Meta Test Events panel for verification. Leave blank in production.',
        ],
    ],
];
