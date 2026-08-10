<?php

return [
    'timeout' => 12,
    'cache_ttl_seconds' => 60,
    'user_agent' => 'mozilla/5.0 (compatible; msie 5.01; windows nt 5.0)',
    'ssl_verify' => env('BLACKHOLE_SSL_VERIFY', env('APP_ENV') !== 'local'),
    'ca_bundle' => env('BLACKHOLE_CA_BUNDLE', ''),
    'upstreams' => [
        'ningbo' => [
            'base_url' => 'http://160.202.238.2:81',
            'blackhole_path' => '/api/blackhole.php',
            'whitelist_path' => '/api/gb.php',
        ],
        'shiyan' => [
            'base_url' => 'http://160.202.238.2:90',
            'blackhole_path' => '/blackhole/blackholeapi.php',
            'layer7_find_path' => '/use/find.php',
            'layer7_toggle_path' => '/use/request.php',
            'layer4_path' => '/through/through.php',
            'flow_path' => '/flow/flowapi.php',
        ],
        'hongkong' => [
            'api_url' => 'https://mianban.288cloud.com/ddos/api/',
        ],
        'public' => [
            'base_url' => 'https://blackhole.jdidc.cn',
            'us1_traffic_base_url' => 'https://do.yazzi.net/index/history',
        ],
    ],
];
