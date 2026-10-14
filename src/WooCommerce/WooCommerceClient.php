<?php
declare(strict_types=1);

namespace Pims\WooCommerce;

use Pims\Config;

/**
 * Thin cURL wrapper around the WooCommerce REST API v3.
 * Credentials never leave this class.
 */
final class WooCommerceClient
{
    private string $baseUrl;
    private string $key;
    private string $secret;
    private bool $verifySsl;
    private int $timeout;

    /** Last HTTP status from the most recent request. */
    public ?int $lastHttpStatus = null;
    /** Truncated response body from the most recent request (for logging). */
    public ?string $lastBody = null;

    public function __construct()
    {
        $cfg = Config::get('woocommerce');
        $this->baseUrl   = rtrim($cfg['url'] ?? '', '/');
        $this->key       = $cfg['consumer_key'] ?? '';
        $this->secret    = $cfg['consumer_secret'] ?? '';
        $this->verifySsl = (bool) ($cfg['verify_ssl'] ?? true);
        $this->timeout   = (int) ($cfg['timeout'] ?? 10);

        if ($this->baseUrl === '' || $this->key === '' || $this->secret === '') {
            throw new WooCommerceException('WooCommerce is not configured. Copy config.example.php to config.php and fill in credentials.');
        }
    }

    // ── Products ───────────────────────────────────────────────────────

    public function getProduct(int $id): ?array
    {
        try {
            return $this->request('GET', "/wp-json/wc/v3/products/{$id}");
        } catch (WooCommerceException $e) {
            if ($e->httpStatus === 404) {
                return null;
            }
            throw $e;
        }
    }

    public function findProductBySku(string $sku): ?array
    {
        $result = $this->request('GET', '/wp-json/wc/v3/products', ['sku' => $sku]);
        if (is_array($result) && count($result) > 0) {
            return $result[0];
        }
        return null;
    }

    public function createProduct(array $data): array
    {
        return $this->request('POST', '/wp-json/wc/v3/products', null, $data);
    }

    public function updateProduct(int $id, array $data): array
    {
        return $this->request('PUT', "/wp-json/wc/v3/products/{$id}", null, $data);
    }

    /**
     * Paginated product list. Excludes trashed by default (status=any still skips trash unless status=trash).
     * @return list<array>
     */
    public function listProducts(int $page = 1, int $perPage = 50): array
    {
        $result = $this->request('GET', '/wp-json/wc/v3/products', [
            'page'     => $page,
            'per_page' => $perPage,
            'status'   => 'any', // draft, pending, private, publish — not trash
        ]);
        return is_array($result) ? $result : [];
    }

    // ── Categories ─────────────────────────────────────────────────────

    public function findCategoryByName(string $name): ?array
    {
        $result = $this->request('GET', '/wp-json/wc/v3/products/categories', [
            'search'   => $name,
            'per_page' => 100,
        ]);
        if (!is_array($result)) {
            return null;
        }
        foreach ($result as $cat) {
            if (strcasecmp($cat['name'] ?? '', $name) === 0) {
                return $cat;
            }
        }
        return null;
    }

    public function createCategory(string $name, ?string $description = null): array
    {
        $body = ['name' => $name];
        if ($description !== null) {
            $body['description'] = $description;
        }
        return $this->request('POST', '/wp-json/wc/v3/products/categories', null, $body);
    }

    public function deleteCategory(int $id): array
    {
        return $this->request('DELETE', "/wp-json/wc/v3/products/categories/{$id}", ['force' => 'true']);
    }

    // ── Global attributes & terms ──────────────────────────────────────

    public function listAttributes(): array
    {
        $result = $this->request('GET', '/wp-json/wc/v3/products/attributes');
        return is_array($result) ? $result : [];
    }

    public function createAttribute(string $name, string $slug = ''): array
    {
        $body = ['name' => $name, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false];
        if ($slug !== '') {
            $body['slug'] = $slug;
        }
        return $this->request('POST', '/wp-json/wc/v3/products/attributes', null, $body);
    }

    public function listAttributeTerms(int $attributeId): array
    {
        $result = $this->request('GET', "/wp-json/wc/v3/products/attributes/{$attributeId}/terms", [
            'per_page' => 100,
        ]);
        return is_array($result) ? $result : [];
    }

    public function createAttributeTerm(int $attributeId, string $name): array
    {
        return $this->request('POST', "/wp-json/wc/v3/products/attributes/{$attributeId}/terms", null, [
            'name' => $name,
        ]);
    }

    // ── HTTP ───────────────────────────────────────────────────────────

    /**
     * @return mixed decoded JSON
     * @throws WooCommerceException
     */
    private function request(string $method, string $path, ?array $query = null, ?array $body = null): mixed
    {
        // WooCommerce auth: HTTPS accepts HTTP Basic (key:secret). Plain HTTP
        // (typical for a local WordPress) rejects Basic and requires the key
        // and secret as query-string parameters instead.
        $useBasicAuth = stripos($this->baseUrl, 'https://') === 0;
        if (!$useBasicAuth) {
            $query = ($query ?? []) + [
                'consumer_key'    => $this->key,
                'consumer_secret' => $this->secret,
            ];
        }

        $url = $this->baseUrl . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $attempt = 0;
        $maxAttempts = 2; // retry once on network error or 5xx

        while (true) {
            $attempt++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
                CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            ]);
            if ($useBasicAuth) {
                curl_setopt($ch, CURLOPT_USERPWD, $this->key . ':' . $this->secret);
            }
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
            }

            $raw      = curl_exec($ch);
            $errno    = curl_errno($ch);
            $error    = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->lastHttpStatus = $httpCode;
            $this->lastBody = is_string($raw) ? mb_substr($raw, 0, 500) : null;

            // Network error → retry once
            if ($errno !== 0) {
                if ($attempt < $maxAttempts) {
                    continue;
                }
                throw new WooCommerceException("Network error: $error", 0);
            }

            // 5xx → retry once
            if ($httpCode >= 500 && $attempt < $maxAttempts) {
                continue;
            }

            // Non-JSON or empty
            $decoded = json_decode((string) $raw, true);
            if ($raw !== '' && $raw !== false && $decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new WooCommerceException(
                    'Malformed WooCommerce response (not JSON). Body: ' . mb_substr((string) $raw, 0, 200),
                    $httpCode
                );
            }

            if ($httpCode >= 400) {
                $msg = is_array($decoded)
                    ? ($decoded['message'] ?? $decoded['code'] ?? "HTTP $httpCode")
                    : "HTTP $httpCode";
                throw new WooCommerceException((string) $msg, $httpCode, is_array($decoded) ? $decoded : null);
            }

            return $decoded;
        }
    }
}
