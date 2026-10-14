<?php
// Copy this file to config/config.php and fill in real values.
// config/config.php is git-ignored. Never commit real credentials.

return [
    'db' => [
        'host'    => '127.0.0.1',
        'name'    => 'pims',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'woocommerce' => [
        'url'             => 'https://your-store.test', // no trailing slash. http://localhost/... is fine for a local site
        'consumer_key'    => 'ck_xxxxxxxxxxxxxxxx',
        'consumer_secret' => 'cs_xxxxxxxxxxxxxxxx',
        'verify_ssl'      => true,  // set false ONLY for a local self-signed cert
        'timeout'         => 10,
    ],
];
