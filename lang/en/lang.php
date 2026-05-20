<?php

return [
    'plugin' => [
        'name' => 'Meta Pixel + Conversions API',
        'description' => 'Server-deduplicated Meta Pixel and Conversions API tracking via adapter pattern.',
    ],
    'tab' => [
        'pixel_and_capi' => 'Pixel & CAPI',
        'hosts_and_cookies' => 'Hosts & Cookies',
        'theme_tracking' => 'Theme Tracking',
        'advanced' => 'Advanced',
    ],
    'settings' => [
        'label' => 'Meta Pixel + CAPI',
        'description' => 'Configure the Pixel ID, CAPI access token, and Test Events code for Meta tracking.',
        'category' => 'Marketing',
        'fields' => [
            'pixel_id_label' => 'Pixel ID',
            'pixel_id_comment' => 'Your Meta Pixel ID (digits-only). Acquire from Meta Events Manager > Data sources > Pixel > Settings.',
            'capi_access_token_label' => 'CAPI Access Token',
            'capi_access_token_comment' => 'Conversions API access token. Acquire from Meta Events Manager > Settings > Generate access token.',
            'test_event_code_label' => 'Test Events Code',
            'test_event_code_comment' => 'Optional. Routes events to Meta Test Events panel for verification. Leave blank in production.',
            'paid_status_code_label' => 'Paid status code',
            'paid_status_code_comment' => 'The Shopaholic order status that triggers a Purchase event (one value). Acquire status codes from Shopaholic > Settings > Order statuses. Default: new-payment-received.',
            'default_currency_code_label' => 'Default currency code',
            'default_currency_code_comment' => 'ISO 4217 currency code used as the fallback when an Order or CartPosition has no currency relation or currency_code field. Required by Meta CAPI for every event.',
            'theme_custom_event_names_label' => 'Custom theme event names',
            'theme_custom_event_names_comment' => 'Operator-supplied event names allowed by the theme AJAX handler. One per line. Alphanumeric + underscore, 1-50 chars. Standard Meta events (PageView, ViewContent, AddToCart, Purchase, Lead, ...) do not need to be listed here.',
            'trusted_hosts_label' => 'Trusted hosts',
            'trusted_hosts_comment' => 'Hosts allowed to receive server-side _fbp / _fbc cookies. One host per line (no scheme, no path). Each host is validated against the Public Suffix List on save; unknown TLDs are rejected. Example: example.com, www.example.com, shop.example.co.uk.',
            'ensure_fbp_fbc_label' => 'Set _fbp / _fbc cookies server-side',
            'ensure_fbp_fbc_comment' => 'Disable if your theme already writes these cookies, or for GDPR consent-banner integration where cookies must wait for opt-in.',
        ],
    ],
];
