<?php
declare(strict_types=1);

// Usage: php database/test_woo.php
// Checks the WooCommerce connection and credentials from config/config.php.

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Pims\Config;
use Pims\WooCommerce\WooCommerceClient;
use Pims\WooCommerce\WooCommerceException;

$url = (string) Config::get('woocommerce.url', '');
echo "Store URL : {$url}\n";
echo 'Auth mode : ' . (stripos($url, 'https://') === 0 ? 'HTTPS (Basic Auth)' : 'HTTP (query-string keys)') . "\n\n";

try {
    $client = new WooCommerceClient();

    // Read check
    $products = $client->listProducts(1, 1);
    echo "[OK] Read access: products endpoint reachable.\n";

    // Write check: create and immediately remove a throwaway category
    $name = 'pims-connection-test-' . time();
    $cat  = $client->createCategory($name);
    echo "[OK] Write access: created test category #{$cat['id']}.\n";
    try {
        $client->deleteCategory((int) $cat['id']);
        echo "[OK] Cleaned up test category.\n";
    } catch (WooCommerceException $e) {
        echo "[..] Could not delete test category #{$cat['id']} ({$e->getMessage()}). Remove it in WP admin.\n";
    }

    echo "\nConnection looks good.\n";
} catch (WooCommerceException $e) {
    echo "[FAIL] {$e->getMessage()} (HTTP {$e->httpStatus})\n\n";
    $hints = [
        0   => 'Cannot reach the store. Check woocommerce.url in config/config.php and that WordPress is running.',
        401 => 'Credentials rejected. Re-copy the consumer key/secret; make sure the key has Read/Write permission.',
        403 => 'Key is valid but lacks permission. Generate a key with Read/Write permission.',
        404 => 'REST route not found. In WP admin go to Settings > Permalinks and click Save once (must not be "Plain").',
    ];
    echo ($hints[$e->httpStatus] ?? 'See the message above.') . "\n";
    exit(1);
}
