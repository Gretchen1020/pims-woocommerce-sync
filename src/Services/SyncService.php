<?php
declare(strict_types=1);

namespace Pims\Services;

use Pims\Database;
use Pims\Http\HttpException;
use Pims\Repositories\AttributeRepository;
use Pims\Repositories\CategoryRepository;
use Pims\Repositories\ProductRepository;
use Pims\Repositories\SyncLogRepository;
use Pims\WooCommerce\WooCommerceClient;
use Pims\WooCommerce\WooCommerceException;

final class SyncService
{
    private WooCommerceClient $wc;

    public function __construct(?WooCommerceClient $client = null)
    {
        $this->wc = $client ?? new WooCommerceClient();
    }

    // ── Single-product sync ────────────────────────────────────────────

    public function syncProduct(int $productId): array
    {
        ProductRepository::clearStaleSyncing();

        $product = ProductRepository::findById($productId);
        if (!$product) {
            throw new HttpException(404, 'not_found', 'Product not found.');
        }
        if ($product['sync_status'] === 'SYNCING') {
            throw new HttpException(409, 'sync_in_progress', 'A sync is already in progress for this product.');
        }
        if ($product['sync_status'] === 'CONFLICT') {
            throw new HttpException(409, 'conflict', 'Resolve conflicts before syncing again.');
        }

        ProductRepository::setSyncStatus($productId, 'SYNCING');

        try {
            $result = $this->doSync($product);
            return $result;
        } catch (WooCommerceException $e) {
            ProductRepository::setSyncStatus($productId, 'FAILED', $e->getMessage());
            SyncLogRepository::create($productId, 'push', 'FAILED', $e->getMessage(), $e->httpStatus);
            throw new HttpException(502, 'woocommerce_error', $e->getMessage());
        } catch (\Throwable $e) {
            ProductRepository::setSyncStatus($productId, 'FAILED', $e->getMessage());
            SyncLogRepository::create($productId, 'push', 'FAILED', $e->getMessage());
            throw $e;
        }
    }

    private function doSync(array $product): array
    {
        $productId = (int) $product['id'];
        $wcProduct = null;

        // Resolve or create the WooCommerce product
        if ($product['woocommerce_id']) {
            $wcProduct = $this->wc->getProduct((int) $product['woocommerce_id']);
            if ($wcProduct === null) {
                // Was deleted in WC — treat as new
                $product['woocommerce_id'] = null;
            }
        }

        if (!$product['woocommerce_id']) {
            $wcProduct = $this->wc->findProductBySku($product['sku']);
            if ($wcProduct) {
                // Link existing
                $product['woocommerce_id'] = $wcProduct['id'];
            }
        }

        if ($wcProduct) {
            return $this->mergeAndPush($product, $wcProduct);
        }

        // Create new in WooCommerce
        return $this->createInWooCommerce($product);
    }

    private function createInWooCommerce(array $product): array
    {
        $productId = (int) $product['id'];
        $payload = $this->buildWcPayload($product, isFirstPush: true);
        $created = $this->wc->createProduct($payload);
        $wcId = (int) $created['id'];

        $snapshot = $this->buildSnapshot($product, $created);
        $stock = isset($created['stock_quantity']) ? (int) $created['stock_quantity'] : (int) $product['stock'];

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            ProductRepository::applySyncSuccess($productId, $wcId, $snapshot, 'push', $stock);
            SyncLogRepository::create($productId, 'push', 'SUCCESS', null, $this->wc->lastHttpStatus);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ProductRepository::findById($productId);
    }

    private function mergeAndPush(array $product, array $wcProduct): array
    {
        $productId = (int) $product['id'];
        $snapshot = $product['wc_snapshot']
            ? (is_string($product['wc_snapshot']) ? json_decode($product['wc_snapshot'], true) : $product['wc_snapshot'])
            : null;

        $fields = ['name', 'description', 'price', 'status', 'category', 'attributes'];
        $conflicts = [];
        $toPush = [];
        $toPull = [];

        // Map WC values into a comparable shape
        $wcMapped = $this->mapWcToPims($wcProduct);

        foreach ($fields as $field) {
            $snapVal = $snapshot[$field] ?? null;
            $pimsVal = $this->fieldValue($product, $field);
            $wcVal   = $wcMapped[$field] ?? null;

            $snapEqPims = $this->valuesEqual($snapVal, $pimsVal);
            $snapEqWc   = $this->valuesEqual($snapVal, $wcVal);
            $pimsEqWc   = $this->valuesEqual($pimsVal, $wcVal);

            if ($snapEqPims && $snapEqWc) {
                // No change
                continue;
            }
            if (!$snapEqPims && $snapEqWc) {
                // PIMS changed → push
                $toPush[$field] = $pimsVal;
                continue;
            }
            if ($snapEqPims && !$snapEqWc) {
                // WC changed → pull
                $toPull[$field] = $wcVal;
                continue;
            }
            // Both changed
            if ($pimsEqWc) {
                // Same value, just update snapshot later
                continue;
            }
            $conflicts[$field] = [
                'pims'        => $pimsVal,
                'woocommerce' => $wcVal,
                'snapshot'    => $snapVal,
            ];
        }

        // Stock: always pull from WC after first push, never conflict
        $stockFromWc = isset($wcProduct['stock_quantity']) ? (int) $wcProduct['stock_quantity'] : null;

        if ($conflicts) {
            ProductRepository::setConflict($productId, $conflicts);
            SyncLogRepository::create($productId, 'merge', 'CONFLICT', 'Field-level conflicts detected');
            return ProductRepository::findById($productId);
        }

        // Apply pulls to PIMS
        if ($toPull) {
            $this->applyPulls($productId, $toPull, $wcMapped);
            // Reload product after pulls
            $product = ProductRepository::findById($productId);
        }

        // Push remaining PIMS changes (and always ensure category/attributes are correct)
        $payload = $this->buildWcPayload($product, isFirstPush: false, onlyFields: array_keys($toPush) ?: null);
        // Write to WooCommerce only when PIMS has changes to push. On a clean
        // pull-only merge, reuse the WC product we already fetched.
        $updated = $toPush
            ? $this->wc->updateProduct((int) $wcProduct['id'], $payload)
            : $wcProduct;

        // If only stock pull and no other changes, still update snapshot
        $newSnapshot = $this->buildSnapshot($product, $updated);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            ProductRepository::applySyncSuccess(
                $productId,
                (int) $wcProduct['id'],
                $newSnapshot,
                $toPush ? 'push' : ($toPull ? 'pull' : 'sync'),
                $stockFromWc
            );
            SyncLogRepository::create($productId, 'merge', 'SUCCESS', null, $this->wc->lastHttpStatus);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ProductRepository::findById($productId);
    }

    // ── Conflict resolution ────────────────────────────────────────────

    /**
     * @param array{choices?:array<string,string>, keep_all?:string} $input
     *   choices: field => 'pims'|'woocommerce'
     *   keep_all: 'pims'|'woocommerce'
     */
    public function resolveConflict(int $productId, array $input): array
    {
        $product = ProductRepository::findById($productId);
        if (!$product) {
            throw new HttpException(404, 'not_found', 'Product not found.');
        }
        if ($product['sync_status'] !== 'CONFLICT') {
            throw new HttpException(409, 'not_in_conflict', 'Product is not in CONFLICT status.');
        }

        $conflictFields = is_string($product['conflict_fields'])
            ? json_decode($product['conflict_fields'], true)
            : ($product['conflict_fields'] ?? []);

        if (empty($conflictFields)) {
            throw new HttpException(409, 'no_conflicts', 'No conflict data found.');
        }

        $choices = [];
        if (!empty($input['keep_all']) && in_array($input['keep_all'], ['pims', 'woocommerce'], true)) {
            foreach (array_keys($conflictFields) as $field) {
                $choices[$field] = $input['keep_all'];
            }
        } elseif (!empty($input['choices']) && is_array($input['choices'])) {
            foreach ($conflictFields as $field => $vals) {
                $c = $input['choices'][$field] ?? null;
                if (!in_array($c, ['pims', 'woocommerce'], true)) {
                    throw new HttpException(422, 'validation_error', "Missing or invalid choice for field: $field");
                }
                $choices[$field] = $c;
            }
        } else {
            throw new HttpException(422, 'validation_error', 'Provide choices map or keep_all.');
        }

        // Apply chosen values to PIMS where user picked WooCommerce
        $pulls = [];
        foreach ($choices as $field => $choice) {
            if ($choice === 'woocommerce') {
                $pulls[$field] = $conflictFields[$field]['woocommerce'];
            }
            // If pims, leave PIMS as-is; the next sync will push
        }
        if ($pulls) {
            $this->applyPulls($productId, $pulls, $pulls);
        }

        // Clear conflict so sync can proceed
        ProductRepository::setSyncStatus($productId, 'NOT_SYNCED');
        $stmt = Database::connection()->prepare('UPDATE products SET conflict_fields = NULL WHERE id = :id');
        $stmt->execute([':id' => $productId]);

        // Re-run sync
        return $this->syncProduct($productId);
    }

    // ── Refresh stock ──────────────────────────────────────────────────

    public function refreshStock(int $productId): array
    {
        $product = ProductRepository::findById($productId);
        if (!$product) {
            throw new HttpException(404, 'not_found', 'Product not found.');
        }
        if (!$product['woocommerce_id']) {
            throw new HttpException(409, 'not_synced', 'Product has not been synced yet.');
        }
        try {
            $wcProduct = $this->wc->getProduct((int) $product['woocommerce_id']);
            if (!$wcProduct) {
                throw new HttpException(404, 'woocommerce_not_found', 'Product no longer exists in WooCommerce.');
            }
            $stock = isset($wcProduct['stock_quantity']) ? (int) $wcProduct['stock_quantity'] : 0;
            ProductRepository::applyFieldValues($productId, ['stock' => $stock]);
            SyncLogRepository::create($productId, 'pull', 'SUCCESS', 'Stock refreshed', $this->wc->lastHttpStatus);
            return ProductRepository::findById($productId);
        } catch (WooCommerceException $e) {
            SyncLogRepository::create($productId, 'pull', 'FAILED', $e->getMessage(), $e->httpStatus);
            throw new HttpException(502, 'woocommerce_error', $e->getMessage());
        }
    }

    // ── Bulk import ────────────────────────────────────────────────────

    public function import(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        $page = 1;

        try {
            while (true) {
                $wcProducts = $this->wc->listProducts($page, 50);
                if (empty($wcProducts)) {
                    break;
                }
                foreach ($wcProducts as $wcProduct) {
                    try {
                        $this->importOne($wcProduct, $stats);
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        SyncLogRepository::create(
                            null,
                            'import',
                            'FAILED',
                            'WC#' . ($wcProduct['id'] ?? '?') . ': ' . $e->getMessage(),
                            $this->wc->lastHttpStatus
                        );
                    }
                }
                if (count($wcProducts) < 50) {
                    break;
                }
                $page++;
            }
            SyncLogRepository::create(null, 'import', 'SUCCESS', json_encode($stats));
        } catch (WooCommerceException $e) {
            SyncLogRepository::create(null, 'import', 'FAILED', $e->getMessage(), $e->httpStatus);
            throw new HttpException(502, 'woocommerce_error', $e->getMessage());
        }

        return $stats;
    }

    private function importOne(array $wcProduct, array &$stats): void
    {
        $sku = trim((string) ($wcProduct['sku'] ?? ''));
        if ($sku === '') {
            $stats['skipped']++;
            SyncLogRepository::create(null, 'import', 'SKIPPED', 'WC#' . $wcProduct['id'] . ' has no SKU');
            return;
        }

        // Match by woocommerce_id then SKU
        $existing = ProductRepository::findByWooCommerceId((int) $wcProduct['id']);
        if (!$existing) {
            $existing = ProductRepository::findBySku($sku);
        }

        if ($existing) {
            // Run through three-way merge
            $existing['woocommerce_id'] = $existing['woocommerce_id'] ?: $wcProduct['id'];
            // Temporarily set status so merge can run
            if ($existing['sync_status'] === 'SYNCING') {
                $stats['skipped']++;
                return;
            }
            ProductRepository::setSyncStatus((int) $existing['id'], 'SYNCING');
            try {
                $this->mergeAndPush($existing, $wcProduct);
                $stats['updated']++;
            } catch (\Throwable $e) {
                ProductRepository::setSyncStatus((int) $existing['id'], 'FAILED', $e->getMessage());
                throw $e;
            }
            return;
        }

        // Create as draft in PIMS
        $categoryId = $this->resolveCategoryFromWc($wcProduct);
        $status = $this->mapWcStatusToPims($wcProduct['status'] ?? 'draft');
        // Import as draft per spec for unmatched
        $product = ProductRepository::create([
            'sku'          => $sku,
            'name'         => $wcProduct['name'] ?? $sku,
            'description'  => $wcProduct['description'] ?? null,
            'category_id'  => $categoryId,
            'brand'        => null,
            'price'        => $wcProduct['regular_price'] !== '' ? $wcProduct['regular_price'] : '0.00',
            'stock'        => (int) ($wcProduct['stock_quantity'] ?? 0),
            'status'       => 'draft',
            'sync_status'  => 'SYNCED',
        ]);
        $productId = (int) $product['id'];

        // Link and store snapshot
        $snapshot = $this->buildSnapshot($product, $wcProduct);
        ProductRepository::applySyncSuccess(
            $productId,
            (int) $wcProduct['id'],
            $snapshot,
            'import',
            isset($wcProduct['stock_quantity']) ? (int) $wcProduct['stock_quantity'] : null
        );
        // Import custom attributes if present
        $this->importAttributes($productId, $wcProduct);

        SyncLogRepository::create($productId, 'import', 'SUCCESS', null, $this->wc->lastHttpStatus);
        $stats['created']++;
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function buildWcPayload(array $product, bool $isFirstPush, ?array $onlyFields = null): array
    {
        $should = function (string $f) use ($onlyFields): bool {
            return $onlyFields === null || in_array($f, $onlyFields, true);
        };

        $payload = [];

        if ($should('name') || $isFirstPush) {
            $payload['name'] = $product['name'];
        }
        if ($should('description') || $isFirstPush) {
            $payload['description'] = $product['description'] ?? '';
        }
        if ($should('price') || $isFirstPush) {
            $payload['regular_price'] = (string) $product['price'];
        }
        if ($isFirstPush) {
            $payload['sku'] = $product['sku'];
            $payload['manage_stock'] = true;
            $payload['stock_quantity'] = (int) $product['stock'];
        }
        if ($should('status') || $isFirstPush) {
            $payload['status'] = $this->mapPimsStatusToWc($product['status']);
        }
        if ($should('category') || $isFirstPush) {
            $cat = CategoryRepository::findById((int) $product['category_id']);
            if ($cat) {
                $wcCatId = $this->ensureWcCategory($cat);
                $payload['categories'] = [['id' => $wcCatId]];
            }
        }
        if ($should('attributes') || $isFirstPush) {
            $payload['attributes'] = $this->buildWcAttributes($product);
        }

        return $payload;
    }

    private function ensureWcCategory(array $cat): int
    {
        if ($cat['woocommerce_id']) {
            return (int) $cat['woocommerce_id'];
        }
        $existing = $this->wc->findCategoryByName($cat['name']);
        if ($existing) {
            CategoryRepository::setWooCommerceId((int) $cat['id'], (int) $existing['id']);
            return (int) $existing['id'];
        }
        $created = $this->wc->createCategory($cat['name'], $cat['description']);
        CategoryRepository::setWooCommerceId((int) $cat['id'], (int) $created['id']);
        return (int) $created['id'];
    }

    private function buildWcAttributes(array $product): array
    {
        $attrs = $product['attributes'] ?? ProductRepository::getAttributeValues((int) $product['id']);
        $result = [];
        $position = 0;

        foreach ($attrs as $a) {
            $attrDef = AttributeRepository::findById((int) $a['attribute_id']);
            if (!$attrDef) {
                continue;
            }
            $val = $a['value'] ?? null;
            if ($val === null || $val === '') {
                continue;
            }

            if ($attrDef['data_type'] === 'select') {
                // Global attribute
                $wcAttrId = $this->ensureWcGlobalAttribute($attrDef);
                $opt = AttributeRepository::findOption((int) $val);
                if (!$opt) {
                    continue;
                }
                $termId = $this->ensureWcTerm($attrDef, $opt);
                $result[] = [
                    'id'        => $wcAttrId,
                    'name'      => $attrDef['name'],
                    'position'  => $position++,
                    'visible'   => true,
                    'variation' => false,
                    'options'   => [$opt['value']],
                ];
            } else {
                // Custom per-product attribute
                $display = match ($attrDef['data_type']) {
                    'boolean' => $val ? 'Yes' : 'No',
                    'unit'    => $val . ($attrDef['unit'] ? ' ' . $attrDef['unit'] : ''),
                    default   => (string) $val,
                };
                $result[] = [
                    'name'      => $attrDef['name'],
                    'position'  => $position++,
                    'visible'   => true,
                    'variation' => false,
                    'options'   => [$display],
                ];
            }
        }
        return $result;
    }

    private function ensureWcGlobalAttribute(array $attrDef): int
    {
        if ($attrDef['woocommerce_id']) {
            return (int) $attrDef['woocommerce_id'];
        }
        // Look for existing by name
        $existing = $this->wc->listAttributes();
        foreach ($existing as $ea) {
            if (strcasecmp($ea['name'] ?? '', $attrDef['name']) === 0) {
                AttributeRepository::setWooCommerceId((int) $attrDef['id'], (int) $ea['id']);
                return (int) $ea['id'];
            }
        }
        $created = $this->wc->createAttribute($attrDef['name']);
        AttributeRepository::setWooCommerceId((int) $attrDef['id'], (int) $created['id']);
        return (int) $created['id'];
    }

    private function ensureWcTerm(array $attrDef, array $opt): int
    {
        if ($opt['woocommerce_term_id']) {
            return (int) $opt['woocommerce_term_id'];
        }
        $wcAttrId = (int) ($attrDef['woocommerce_id'] ?: $this->ensureWcGlobalAttribute($attrDef));
        $terms = $this->wc->listAttributeTerms($wcAttrId);
        foreach ($terms as $t) {
            if (strcasecmp($t['name'] ?? '', $opt['value']) === 0) {
                AttributeRepository::setOptionTermId((int) $opt['id'], (int) $t['id']);
                return (int) $t['id'];
            }
        }
        $created = $this->wc->createAttributeTerm($wcAttrId, $opt['value']);
        AttributeRepository::setOptionTermId((int) $opt['id'], (int) $created['id']);
        return (int) $created['id'];
    }

    private function mapWcToPims(array $wc): array
    {
        return [
            'name'        => $wc['name'] ?? '',
            'description' => $wc['description'] ?? '',
            'price'       => isset($wc['regular_price']) && $wc['regular_price'] !== ''
                ? number_format((float) $wc['regular_price'], 2, '.', '')
                : '0.00',
            'status'      => $this->mapWcStatusToPims($wc['status'] ?? 'draft'),
            'category'    => $this->wcCategoryName($wc),
            'attributes'  => $this->wcAttributesHash($wc),
        ];
    }

    private function fieldValue(array $product, string $field): mixed
    {
        return match ($field) {
            'name'        => $product['name'],
            'description' => $product['description'] ?? '',
            'price'       => number_format((float) $product['price'], 2, '.', ''),
            'status'      => $product['status'],
            'category'    => CategoryRepository::findById((int) $product['category_id'])['name'] ?? '',
            'attributes'  => $this->pimsAttributesHash($product),
            default       => null,
        };
    }

    private function pimsAttributesHash(array $product): string
    {
        $attrs = $product['attributes'] ?? ProductRepository::getAttributeValues((int) $product['id']);
        $pairs = [];
        foreach ($attrs as $a) {
            $val = $a['option_value'] ?? $a['value'] ?? '';
            if ($a['data_type'] === 'unit' && $a['unit']) {
                $val = $val . ' ' . $a['unit'];
            }
            if ($a['data_type'] === 'boolean') {
                $val = $val ? 'Yes' : 'No';
            }
            $pairs[$a['attribute_name']] = (string) $val;
        }
        ksort($pairs);
        return json_encode($pairs, JSON_UNESCAPED_UNICODE);
    }

    private function wcAttributesHash(array $wc): string
    {
        $pairs = [];
        foreach ($wc['attributes'] ?? [] as $a) {
            $opts = $a['options'] ?? [];
            $pairs[$a['name'] ?? ''] = implode(', ', $opts);
        }
        ksort($pairs);
        return json_encode($pairs, JSON_UNESCAPED_UNICODE);
    }

    private function wcCategoryName(array $wc): string
    {
        $cats = $wc['categories'] ?? [];
        return $cats[0]['name'] ?? '';
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        // Normalize numbers
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.001;
        }
        return (string) $a === (string) $b;
    }

    private function buildSnapshot(array $product, array $wcProduct): array
    {
        return $this->mapWcToPims($wcProduct);
        // Snapshot stores the last-agreed values in PIMS-comparable form
    }

    private function applyPulls(int $productId, array $toPull, array $wcMapped): void
    {
        $fields = [];
        if (isset($toPull['name'])) {
            $fields['name'] = $toPull['name'];
        }
        if (isset($toPull['description'])) {
            $fields['description'] = $toPull['description'];
        }
        if (isset($toPull['price'])) {
            $fields['price'] = $toPull['price'];
        }
        if (isset($toPull['status'])) {
            $fields['status'] = $toPull['status'];
        }
        // category: resolve by name if needed — skip complex category pull for MVP if name-only
        // attributes: complex; skip auto-apply attribute pulls for MVP (user resolves via conflict UI)
        if ($fields) {
            ProductRepository::applyFieldValues($productId, $fields);
        }
    }

    private function mapPimsStatusToWc(string $status): string
    {
        return match ($status) {
            'active'   => 'publish',
            'draft'    => 'draft',
            'inactive' => 'private',
            'archived' => 'trash',
            default    => 'draft',
        };
    }

    private function mapWcStatusToPims(string $status): string
    {
        return match ($status) {
            'publish' => 'active',
            'draft'   => 'draft',
            'private' => 'inactive',
            'trash'   => 'archived',
            'pending' => 'draft',
            default   => 'draft',
        };
    }

    private function resolveCategoryFromWc(array $wcProduct): int
    {
        $cats = $wcProduct['categories'] ?? [];
        if ($cats) {
            $name = $cats[0]['name'] ?? null;
            if ($name) {
                $existing = CategoryRepository::findByName($name);
                if ($existing) {
                    if (!$existing['woocommerce_id'] && !empty($cats[0]['id'])) {
                        CategoryRepository::setWooCommerceId((int) $existing['id'], (int) $cats[0]['id']);
                    }
                    return (int) $existing['id'];
                }
                // Create category in PIMS
                $created = CategoryRepository::create($name, null);
                if (!empty($cats[0]['id'])) {
                    CategoryRepository::setWooCommerceId((int) $created['id'], (int) $cats[0]['id']);
                }
                return (int) $created['id'];
            }
        }
        // Fallback: ensure an "Uncategorized" category
        $uncat = CategoryRepository::findByName('Uncategorized');
        if (!$uncat) {
            $uncat = CategoryRepository::create('Uncategorized', 'Auto-created for imported products');
        }
        return (int) $uncat['id'];
    }

    private function importAttributes(int $productId, array $wcProduct): void
    {
        // Best-effort: map WC custom attributes by name to existing PIMS attributes
        foreach ($wcProduct['attributes'] ?? [] as $wa) {
            $name = $wa['name'] ?? '';
            if ($name === '') {
                continue;
            }
            $attr = AttributeRepository::findByName($name);
            if (!$attr) {
                continue; // don't auto-create attribute definitions on import
            }
            $optVal = ($wa['options'][0] ?? null);
            if ($optVal === null) {
                continue;
            }
            $value = null;
            match ($attr['data_type']) {
                'text'    => $value = (string) $optVal,
                'number', 'unit' => is_numeric($optVal) ? $value = (float) $optVal : $value = null,
                'boolean' => $value = in_array(strtolower((string) $optVal), ['yes', 'true', '1'], true) ? true : false,
                'select'  => (function () use ($attr, $optVal, &$value) {
                    foreach (AttributeRepository::getOptions((int) $attr['id']) as $o) {
                        if (strcasecmp($o['value'], (string) $optVal) === 0) {
                            $value = (int) $o['id'];
                            return;
                        }
                    }
                })(),
                default => null,
            };
            if ($value !== null) {
                ProductRepository::setAttributeValues($productId, [
                    ['attribute_id' => (int) $attr['id'], 'value' => $value],
                ]);
            }
        }
    }

    public function getSyncStatus(int $productId): array
    {
        ProductRepository::clearStaleSyncing();
        $p = ProductRepository::findById($productId);
        if (!$p) {
            throw new HttpException(404, 'not_found', 'Product not found.');
        }
        $conflict = $p['conflict_fields'];
        if (is_string($conflict)) {
            $conflict = json_decode($conflict, true);
        }
        return [
            'id'                  => (int) $p['id'],
            'sync_status'         => $p['sync_status'],
            'last_synced_at'      => $p['last_synced_at'],
            'last_sync_direction' => $p['last_sync_direction'],
            'last_sync_error'     => $p['last_sync_error'],
            'woocommerce_id'      => $p['woocommerce_id'],
            'conflict_fields'     => $conflict,
        ];
    }

    public function listLogs(int $page = 1, int $limit = 50, ?int $productId = null): array
    {
        return SyncLogRepository::list($page, $limit, $productId);
    }
}
