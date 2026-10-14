<?php
declare(strict_types=1);

namespace Pims\Repositories;

use Pims\Database;
use PDO;

final class ProductRepository
{
    /**
     * @param array{
     *   search?:string, category_id?:int, brand?:string, status?:string,
     *   include_archived?:bool, page?:int, limit?:int
     * } $filters
     * @return array{items:list, total:int, page:int, limit:int}
     */
    public static function search(array $filters = []): array
    {
        $page  = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where  = [];
        $params = [];

        if (empty($filters['include_archived'])) {
            $where[] = "p.status != 'archived'";
        }
        if (!empty($filters['search'])) {
            $where[] = '(p.name LIKE :search OR p.sku LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = :category_id';
            $params[':category_id'] = (int) $filters['category_id'];
        }
        if (isset($filters['brand']) && $filters['brand'] !== '') {
            $where[] = 'p.brand = :brand';
            $params[':brand'] = $filters['brand'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['sync_status'])) {
            $where[] = 'p.sync_status = :sync_status';
            $params[':sync_status'] = $filters['sync_status'];
        }

        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $pdo = Database::connection();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p $sqlWhere");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             $sqlWhere
             ORDER BY p.updated_at DESC
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT p.*, c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE p.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $product = $stmt->fetch() ?: null;
        if ($product) {
            $product['attributes'] = self::getAttributeValues($id);
        }
        return $product;
    }

    public static function findBySku(string $sku): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM products WHERE sku = :sku');
        $stmt->execute([':sku' => $sku]);
        return $stmt->fetch() ?: null;
    }

    public static function findByWooCommerceId(int $wcId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM products WHERE woocommerce_id = :wc_id'
        );
        $stmt->execute([':wc_id' => $wcId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(array $data): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO products
                (sku, name, description, category_id, brand, price, stock, status, sync_status)
             VALUES
                (:sku, :name, :description, :category_id, :brand, :price, :stock, :status, :sync_status)'
        );
        $stmt->execute([
            ':sku'         => $data['sku'],
            ':name'        => $data['name'],
            ':description' => $data['description'] ?? null,
            ':category_id' => (int) $data['category_id'],
            ':brand'       => $data['brand'] ?? null,
            ':price'       => $data['price'],
            ':stock'       => (int) ($data['stock'] ?? 0),
            ':status'      => $data['status'] ?? 'draft',
            ':sync_status' => $data['sync_status'] ?? 'NOT_SYNCED',
        ]);
        return self::findById((int) $pdo->lastInsertId());
    }

    /**
     * Optimistic-lock update. Returns null on version conflict.
     * @param array<string,mixed> $data must include 'version'
     */
    public static function update(int $id, array $data): ?array
    {
        $pdo = Database::connection();
        $sets = [
            'name = :name',
            'description = :description',
            'category_id = :category_id',
            'brand = :brand',
            'price = :price',
            'status = :status',
            'version = version + 1',
            "sync_status = IF(sync_status = 'SYNCED', 'NOT_SYNCED', sync_status)",
        ];
        $params = [
            ':id'          => $id,
            ':version'     => (int) $data['version'],
            ':name'        => $data['name'],
            ':description' => $data['description'] ?? null,
            ':category_id' => (int) $data['category_id'],
            ':brand'       => $data['brand'] ?? null,
            ':price'       => $data['price'],
            ':status'      => $data['status'],
        ];

        // Stock is only writable before first sync (woocommerce_id IS NULL).
        if (array_key_exists('stock', $data)) {
            $sets[] = 'stock = IF(woocommerce_id IS NULL, :stock, stock)';
            $params[':stock'] = (int) $data['stock'];
        }

        // SKU is immutable after first sync — only set if never synced.
        if (array_key_exists('sku', $data)) {
            $sets[] = 'sku = IF(woocommerce_id IS NULL, :sku, sku)';
            $params[':sku'] = $data['sku'];
        }

        $sql = 'UPDATE products SET ' . implode(', ', $sets)
             . ' WHERE id = :id AND version = :version';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            // Either not found or version mismatch.
            return null;
        }
        return self::findById($id);
    }

    public static function hardDelete(int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM products WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public static function archive(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            "UPDATE products SET status = 'archived', sync_status = 'NOT_SYNCED', version = version + 1
             WHERE id = :id AND status != 'archived'"
        );
        $stmt->execute([':id' => $id]);
        return self::findById($id);
    }

    public static function restore(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            "UPDATE products SET status = 'draft', sync_status = 'NOT_SYNCED', version = version + 1
             WHERE id = :id AND status = 'archived'"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0 ? self::findById($id) : null;
    }

    // ── Attribute values ───────────────────────────────────────────────

    public static function getAttributeValues(int $productId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT pa.*, a.name AS attribute_name, a.data_type, a.unit,
                    ao.value AS option_value
             FROM product_attributes pa
             JOIN attributes a ON a.id = pa.attribute_id
             LEFT JOIN attribute_options ao ON ao.id = pa.option_id
             WHERE pa.product_id = :pid
             ORDER BY a.name ASC'
        );
        $stmt->execute([':pid' => $productId]);
        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $r) {
            $val = match ($r['data_type']) {
                'text'    => $r['value_text'],
                'number', 'unit' => $r['value_number'] !== null ? (float) $r['value_number'] : null,
                'boolean' => $r['value_boolean'] !== null ? (bool) $r['value_boolean'] : null,
                'select'  => $r['option_id'] !== null ? (int) $r['option_id'] : null,
                default   => null,
            };
            $result[] = [
                'attribute_id'   => (int) $r['attribute_id'],
                'attribute_name' => $r['attribute_name'],
                'data_type'      => $r['data_type'],
                'unit'           => $r['unit'],
                'value'          => $val,
                'option_value'   => $r['option_value'],
            ];
        }
        return $result;
    }

    /**
     * Replace all attribute values for a product in one go.
     * @param list<array{attribute_id:int, value:mixed}> $values
     */
    public static function setAttributeValues(int $productId, array $values): void
    {
        $pdo = Database::connection();
        $del = $pdo->prepare('DELETE FROM product_attributes WHERE product_id = :pid');
        $del->execute([':pid' => $productId]);

        if (empty($values)) {
            return;
        }

        $ins = $pdo->prepare(
            'INSERT INTO product_attributes
                (product_id, attribute_id, value_text, value_number, value_boolean, option_id)
             VALUES
                (:pid, :aid, :vtext, :vnum, :vbool, :oid)'
        );

        foreach ($values as $v) {
            $aid  = (int) $v['attribute_id'];
            $attr = AttributeRepository::findById($aid);
            if (!$attr) {
                continue;
            }
            $params = [
                ':pid'   => $productId,
                ':aid'   => $aid,
                ':vtext' => null,
                ':vnum'  => null,
                ':vbool' => null,
                ':oid'   => null,
            ];
            $val = $v['value'] ?? null;
            if ($val === null || $val === '') {
                continue; // skip empty — required check is in the service
            }
            match ($attr['data_type']) {
                'text'    => $params[':vtext'] = (string) $val,
                'number', 'unit' => $params[':vnum'] = (float) $val,
                'boolean' => $params[':vbool'] = $val ? 1 : 0,
                'select'  => $params[':oid'] = (int) $val,
                default   => null,
            };
            $ins->execute($params);
        }
    }

    // ── Sync-related helpers ───────────────────────────────────────────

    public static function setSyncStatus(int $id, string $status, ?string $error = null): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE products SET sync_status = :status, last_sync_error = :error WHERE id = :id'
        );
        $stmt->execute([':id' => $id, ':status' => $status, ':error' => $error]);
    }

    public static function applySyncSuccess(
        int $id,
        int $wcId,
        array $snapshot,
        string $direction,
        ?int $stock = null
    ): void {
        $sql = "UPDATE products SET
                    woocommerce_id = :wc_id,
                    sync_status = 'SYNCED',
                    last_synced_at = NOW(),
                    last_sync_direction = :dir,
                    last_sync_error = NULL,
                    wc_snapshot = :snap,
                    conflict_fields = NULL";
        $params = [
            ':id'    => $id,
            ':wc_id' => $wcId,
            ':dir'   => $direction,
            ':snap'  => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
        ];
        if ($stock !== null) {
            $sql .= ', stock = :stock';
            $params[':stock'] = $stock;
        }
        $sql .= ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }

    public static function setConflict(int $id, array $conflictFields): void
    {
        $stmt = Database::connection()->prepare(
            "UPDATE products SET sync_status = 'CONFLICT', conflict_fields = :cf, last_sync_error = NULL
             WHERE id = :id"
        );
        $stmt->execute([
            ':id' => $id,
            ':cf' => json_encode($conflictFields, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public static function applyFieldValues(int $id, array $fields): void
    {
        $allowed = ['name', 'description', 'price', 'status', 'brand', 'stock'];
        $sets = [];
        $params = [':id' => $id];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            $sets[] = "$k = :$k";
            $params[":$k"] = $v;
        }
        if (empty($sets)) {
            return;
        }
        $sql = 'UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
    }

    public static function clearStaleSyncing(): void
    {
        // SYNCING older than 5 minutes → FAILED
        Database::connection()->exec(
            "UPDATE products SET sync_status = 'FAILED',
                last_sync_error = 'Sync timed out (stale SYNCING state)'
             WHERE sync_status = 'SYNCING'
               AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        );
    }
}
