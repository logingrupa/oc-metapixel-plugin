<?php

return [
    'plugin' => [
        'name'        => 'Meta Pixel + Conversions API',
        'description' => 'Servera puses dedublēta Meta Pixel un Conversions API izsekošana caur adapter modeli.',
    ],
    'settings' => [
        'label'       => 'Meta Pixel + CAPI',
        'description' => 'Konfigurējiet Pixel ID, CAPI piekļuves marķieri un Test Events kodu Meta izsekošanai.',
        'category'    => 'Mārketings',
        'fields' => [
            'pixel_id_label'             => 'Pixel ID',
            'pixel_id_comment'           => 'Jūsu Meta Pixel ID (tikai cipari). Iegūstams no Meta Events Manager > Datu avoti > Pixel > Iestatījumi.',
            'capi_access_token_label'    => 'CAPI piekļuves marķieris',
            'capi_access_token_comment'  => 'Conversions API piekļuves marķieris. Iegūstams no Meta Events Manager > Iestatījumi > Ģenerēt piekļuves marķieri.',
            'test_event_code_label'      => 'Test Events kods',
            'test_event_code_comment'    => 'Neobligāts. Pārvirza notikumus uz Meta Test Events paneli pārbaudei. Atstājiet tukšu produkcijā.',
        ],
    ],
];
