<?php
/**
 * Bank Alfalah — Alfa Payment Gateway (APG) merchant configuration
 * ------------------------------------------------------------------
 * Fill in the values below with the credentials shown in your APG
 * Merchant Portal (Go Live > Access Sandbox > Credentials Generator).
 * NEVER commit real production credentials to a public repo.
 */

return [

    // Switch this to 'production' once you have tested everything on sandbox
    // and generated your production credentials (see "How to Go Live" in the
    // integration guide).
    'environment' => 'sandbox',

    'sandbox' => [
        'hs_url'            => 'https://sandbox.bankalfalah.com/HS/HS/HS',
        'sso_url'           => 'https://sandbox.bankalfalah.com/SSO/SSO/SSO',
        'ipn_url'           => 'https://sandbox.bankalfalah.com/HS/api/IPN/OrderStatus',
        'merchant_id'       => '265397',
        'store_id'          => '560844',
        'merchant_hash'     => 'OUU362MB1urEv2JGNWAMrfLdElW+v9hsxG/F/FH5Jaq0OjSW2dD9phBGihTo+4Kz',
        'merchant_username' => 'anowew',
        'merchant_password' => 'hwLjRStKDdJvFzk4yqF7CA==',
        'key1'              => '6kM4xDv4hnZvbVfd', // AES key, exactly 16 chars
        'key2'              => '4834458143964935', // AES IV,  exactly 16 chars
    ],

    'production' => [
        'hs_url'            => 'https://payments.bankalfalah.com/HS/HS/HS',
        'sso_url'           => 'https://payments.bankalfalah.com/SSO/SSO/SSO',
        'ipn_url'           => 'https://payments.bankalfalah.com/HS/api/IPN/OrderStatus',
        'merchant_id'       => 'REPLACE_WITH_PRODUCTION_MERCHANT_ID',
        'store_id'          => 'REPLACE_WITH_PRODUCTION_STORE_ID',
        'merchant_hash'     => 'REPLACE_WITH_PRODUCTION_MERCHANT_HASH',
        'merchant_username' => 'REPLACE_WITH_PRODUCTION_USERNAME',
        'merchant_password' => 'REPLACE_WITH_PRODUCTION_PASSWORD',
        'key1'              => 'REPLACE_WITH_PRODUCTION_KEY1', // supplied by APG business owner
        'key2'              => 'REPLACE_WITH_PRODUCTION_KEY2',
    ],

    // 1001 = Page Redirection channel (the only mode this kit uses)
    'channel_id' => '1001',

    // Must be a PUBLIC, internet-reachable URL — Bank Alfalah redirects the
    // customer's browser here after the handshake, and again after payment.
    'return_url' => 'https://YOURDOMAIN.com/return.php',

    // Must also be public — Bank Alfalah POSTs an IPN "url" param here in
    // real time when a transaction completes. Register this exact URL in
    // the Merchant Portal (Go Live > Access Sandbox > Listener URL) or it
    // will never be called.
    'listener_url' => 'https://YOURDOMAIN.com/ipn_listener.php',

    // Where order records are stored. A flat JSON file is fine for testing;
    // swap store_order()/get_order()/update_order() in orders.php for real
    // database calls before going live.
    'orders_file' => __DIR__ . '/orders_data.json',
];