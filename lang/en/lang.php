<?php

return [
    'plugin' => [
        'name'        => 'Meta Pixel + CAPI',
        'description' => 'Server-deduplicated Meta Pixel and Conversions API tracking for Lovata Shopaholic.',
    ],
    'settings' => [
        'label'       => 'Meta Pixel',
        'description' => 'Configure Pixel ID, CAPI token, paid-status trigger, queue connection, and cookie behavior.',
    ],
    'component' => [
        'name'        => 'Pixel Head',
        'description' => 'Renders fbq() init + PageView with server-generated event_id. Coexists with the theme\'s facebook_pixel.htm partial.',
    ],
    'exception' => [
        'missing_pixel_config'   => 'Meta Pixel ID is not configured in plugin settings.',
        'missing_capi_token'     => 'Meta CAPI access token is not configured in plugin settings.',
        'order_has_no_currency'  => 'Order has no currency — cannot build Purchase event payload.',
        'order_has_no_items'     => 'Order has no items — cannot build Purchase event payload.',
        'invalid_event_id'       => 'Event ID is not a valid UUID v4.',
        'meta_api_transient'     => 'Meta CAPI request failed with a transient error and will be retried.',
        'meta_api_permanent'     => 'Meta CAPI request failed permanently and was dead-lettered.',
    ],
    'tab' => [
        'tracking'   => 'Tracking',
        'compliance' => 'Compliance',
        'advanced'   => 'Advanced',
    ],
    'field' => [
        'pixel_id'                              => 'Pixel ID',
        'pixel_id_comment'                      => 'Numeric Meta Pixel ID from Events Manager. Required.',
        'capi_access_token'                     => 'CAPI Access Token',
        'capi_access_token_comment'             => 'System-user access token with ads_management scope. Stored encrypted in system_settings.',
        'test_event_code'                       => 'Test Event Code',
        'test_event_code_comment'               => 'Optional. When set, server events appear in Events Manager → Test Events.',
        'currency_code'                         => 'Currency Code',
        'currency_code_comment'                 => 'ISO 4217 default currency for events lacking an explicit value (e.g. EUR, NOK).',
        'phone_country_code'                    => 'Phone Country Code',
        'phone_country_code_comment'            => 'Default E.164 country code applied to unprefixed phone numbers before hashing.',
        'send_hashed_pii'                       => 'Send Hashed PII to CAPI',
        'send_hashed_pii_comment'               => 'When ON, sha256-hashed email / phone / name fields are sent server-side. Required for EMQ ≥ 8.',
        'queue_connection'                      => 'Queue Connection',
        'queue_connection_comment'              => 'Laravel queue driver used by SendCapiEvent. Default: database.',
        'paid_status_code'                      => 'Paid Status Code',
        'paid_status_code_comment'              => 'OrdersShopaholic Status whose entry triggers Purchase CAPI dispatch. Default: new-payment-received.',
        'refire_purchase_on_status_flip'        => 'Re-fire Purchase on Status Flip',
        'refire_purchase_on_status_flip_comment' => 'Off by default. When OFF, an order with a populated meta_purchase_event_id never re-fires Purchase.',
        'ensure_fbp_fbc_server_side'            => 'Set _fbp / _fbc Server-side',
        'ensure_fbp_fbc_server_side_comment'    => 'When ON, the EnsureFbpFbcCookies middleware sets _fbp / _fbc when missing. Fixes the empty-cookie bug.',
    ],
];
