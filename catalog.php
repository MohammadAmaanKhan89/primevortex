<?php
/**
 * Master price catalog — PKR only.
 * This MUST be kept in sync with the catalog embedded in checkout.html's
 * <script> block. The server never trusts a price sent by the browser —
 * it looks up service/package/extra ids here and recomputes the total,
 * so a tampered client-side request can't change what gets charged.
 */

return [

    'services' => [
        'logo'    => 'Logo Design',
        'website' => 'Website Design & Development',
        'app'     => 'Mobile Application Development',
        'seo'     => 'SEO & Digital Marketing',
    ],

    'packages' => [
        'logo' => [
            'startup'   => ['name' => 'Logo Basic',       'price' => 14999,  'delivery' => '2 days'],
            'classic'   => ['name' => 'Logo Plus',        'price' => 29999,  'delivery' => '3 days'],
            'premium'   => ['name' => 'Logo Infinity',    'price' => 49999, 'delivery' => '4 days'],
        ],
        'website' => [
            'starter'      => ['name' => 'Starter',      'price' => 29999,  'delivery' => '7 days'],
            'business'     => ['name' => 'Business',     'price' => 69999,  'delivery' => '12 days'],
            'professional' => ['name' => 'Professional', 'price' => 129999, 'delivery' => '18 days'],
            'enterprise'   => ['name' => 'Enterprise',   'price' => 299999, 'delivery' => '30 days'],
        ],
        'app' => [
            'basic'      => ['name' => 'Basic',      'price' => 99999,  'delivery' => '20 days'],
            'standard'   => ['name' => 'Standard',   'price' => 249999, 'delivery' => '35 days'],
            'advanced'   => ['name' => 'Advanced',   'price' => 499999, 'delivery' => '50 days'],
            'enterprise' => ['name' => 'Enterprise', 'price' => 999999, 'delivery' => '75 days'],
        ],
        'seo' => [
            'basic'      => ['name' => 'Basic',      'price' => 14999, 'delivery' => '14 days'],
            'growth'     => ['name' => 'Growth',     'price' => 29999, 'delivery' => '21 days'],
            'pro'        => ['name' => 'Pro',        'price' => 59999, 'delivery' => '30 days'],
            'enterprise' => ['name' => 'Enterprise', 'price' => 99999, 'delivery' => '45 days'],
        ],
    ],

    'extras' => [
        'social'      => ['name' => 'Social Media Kit',       'price' => 3999],
        'card'        => ['name' => 'Business Card Design',   'price' => 2999],
        'stationery'  => ['name' => 'Stationery Design',      'price' => 3499],
        'fast'        => ['name' => 'Extra Fast Delivery',    'price' => 5999],
        'source'      => ['name' => 'Source Files',           'price' => 2499],
        'seo'         => ['name' => 'SEO Optimization',       'price' => 7999],
        'hosting'     => ['name' => 'Hosting Setup',          'price' => 4999],
        'domain'      => ['name' => 'Domain Registration',    'price' => 1999],
        'maintenance' => ['name' => 'Maintenance (1yr)',      'price' => 9999],
        'appstore'    => ['name' => 'App Store Publishing',   'price' => 6999],
    ],

    'promo_codes' => [
        'PRIME10' => 0.10,
    ],
];