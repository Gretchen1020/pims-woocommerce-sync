<?php
declare(strict_types=1);

namespace Pims\Services;

use Pims\Database;
use Pims\Http\HttpException;
use Pims\Repositories\AttributeRepository;
use Pims\Repositories\CategoryRepository;
use Pims\Repositories\ProductRepository;

final class CatalogService
{
    // ── Categories ─────────────────────────────────────────────────────

    public static function listCategories(): array
    {
        return CategoryRepository::findAll();
    }

    public static function getCategory(int $id): array
    {
        $cat = CategoryRepository::findById($id);
        if (!$cat) {
            throw new HttpException(404, 'not_found', 'Category not found.');
        }
        $cat['attributes'] = CategoryRepository::getAttributes($id);
        return $cat;
    }

    public static function createCategory(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new HttpException(422, 'validation_error', 'Validation failed.', ['name' => 'Name is required.']);
        }
        if (CategoryRepository::findByName($name)) {
            throw new HttpException(409, 'duplicate', 'A category with that name already exists.', ['name' => 'Duplicate name.']);
        }
        $desc = isset($input['description']) ? (string) $input['description'] : null;
        return CategoryRepository::create($name, $desc);
    }

    public static function updateCategory(int $id, array $input): array
    {
        if (!CategoryRepository::findById($id)) {
            throw new HttpException(404, 'not_found', 'Category not found.');
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new HttpException(422, 'validation_error', 'Validation failed.', ['name' => 'Name is required.']);
        }
        $existing = CategoryRepository::findByName($name);
        if ($existing && (int) $existing['id'] !== $id) {
            throw new HttpException(409, 'duplicate', 'A category with that name already exists.', ['name' => 'Duplicate name.']);
        }
        $desc = isset($input['description']) ? (string) $input['description'] : null;
        return CategoryRepository::update($id, $name, $desc);
    }

    public static function deleteCategory(int $id): void
    {
        if (!CategoryRepository::findById($id)) {
            throw new HttpException(404, 'not_found', 'Category not found.');
        }
        if (CategoryRepository::productCount($id) > 0) {
            throw new HttpException(409, 'in_use', 'Cannot delete a category that still has products.');
        }
        CategoryRepository::delete($id);
    }

    public static function setCategoryAttributes(int $categoryId, array $assignments): array
    {
        if (!CategoryRepository::findById($categoryId)) {
            throw new HttpException(404, 'not_found', 'Category not found.');
        }
        $clean = [];
        foreach ($assignments as $row) {
            $aid = (int) ($row['attribute_id'] ?? 0);
            if (!AttributeRepository::findById($aid)) {
                throw new HttpException(422, 'validation_error', "Attribute $aid not found.");
            }
            $clean[] = [
                'attribute_id' => $aid,
                'required'     => !empty($row['required']),
            ];
        }
        CategoryRepository::setAttributes($categoryId, $clean);
        return self::getCategory($categoryId);
    }

    // ── Products ───────────────────────────────────────────────────────

    public static function listProducts(array $filters): array
    {
        return ProductRepository::search($filters);
    }

    public static function getProduct(int $id): array
    {
        $p = ProductRepository::findById($id);
        if (!$p) {
            throw new HttpException(404, 'not_found', 'Product not found.');
        }
        return self::publicProduct($p);
    }

    public static function createProduct(array $input): array
    {
        $data = self::validateProductInput($input, isCreate: true);
        self::assertRequiredAttributes($data['category_id'], $input['attributes'] ?? [], $data['status']);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $product = ProductRepository::create($data);
            ProductRepository::setAttributeValues((int) $product['id'], $input['attributes'] ?? []);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::getProduct((int) $product['id']);
    }

    public static function updateProduct(int $id, array $input): array
    {
        $existing = ProductRepository::findById($id);
        if (!$existing) {
            throw new HttpException(404, 'not_found', 'Product not found.');
        }
        if (!isset($input['version'])) {
            throw new HttpException(422, 'validation_error', 'Validation failed.', ['version' => 'Version is required for optimistic locking.']);
        }

        $data = self::validateProductInput($input, isCreate: false, existing: $existing);
        $data['version'] = (int) $input['version'];

        // Category change: drop values for attributes not shared with the new category.
        $oldCatId = (int) $existing['category_id'];
        $newCatId = (int) $data['category_id'];
        if ($oldCatId !== $newCatId && empty($input['confirm_category_change'])) {
            $oldAttrs = array_column(CategoryRepository::getAttributes($oldCatId), 'attribute_id');
            $newAttrs = array_column(CategoryRepository::getAttributes($newCatId), 'attribute_id');
            $lost = array_diff($oldAttrs, $newAttrs);
            if ($lost) {
                throw new HttpException(409, 'category_change', 'Changing category will discard values for attributes not shared with the new category. Resubmit with confirm_category_change=true.', [
                    'lost_attribute_ids' => array_values($lost),
                ]);
            }
        }

        self::assertRequiredAttributes($data['category_id'], $input['attributes'] ?? [], $data['status']);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $product = ProductRepository::update($id, $data);
            if ($product === null) {
                $pdo->rollBack();
                // Distinguish not-found vs version conflict
                if (!ProductRepository::findById($id)) {
                    throw new HttpException(404, 'not_found', 'Product not found.');
                }
                throw new HttpException(409, 'version_conflict', 'The product was modified by another user. Please reload and try again.');
            }
            if (array_key_exists('attributes', $input)) {
                ProductRepository::setAttributeValues($id, $input['attributes']);
            }
            $pdo->commit();
        } catch (HttpException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return self::getProduct($id);
    }

    public static function deleteProduct(int $id): array
    {
        $p = ProductRepository::findById($id);
        if (!$p) {
            throw new HttpException(404, 'not_found', 'Product not found.');
        }
        if ($p['woocommerce_id'] === null) {
            ProductRepository::hardDelete($id);
            return ['deleted' => true, 'hard' => true];
        }
        // Synced → archive
        $archived = ProductRepository::archive($id);
        return self::publicProduct($archived);
    }

    public static function restoreProduct(int $id): array
    {
        $restored = ProductRepository::restore($id);
        if (!$restored) {
            throw new HttpException(404, 'not_found', 'Archived product not found.');
        }
        return self::publicProduct($restored);
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private static function validateProductInput(array $input, bool $isCreate, ?array $existing = null): array
    {
        $fields = [];
        $errors = [];

        // SKU
        if ($isCreate || array_key_exists('sku', $input)) {
            $sku = trim((string) ($input['sku'] ?? ''));
            if ($sku === '') {
                $errors['sku'] = 'SKU is required.';
            } else {
                $dup = ProductRepository::findBySku($sku);
                if ($dup && ($isCreate || (int) $dup['id'] !== (int) $existing['id'])) {
                    throw new HttpException(409, 'duplicate', 'A product with that SKU already exists.', ['sku' => 'Duplicate SKU.']);
                }
                // Immutable after first sync
                if (!$isCreate && $existing['woocommerce_id'] !== null && $sku !== $existing['sku']) {
                    $errors['sku'] = 'SKU cannot be changed after the product has been synced.';
                }
                $fields['sku'] = $sku;
            }
        } elseif ($isCreate) {
            $errors['sku'] = 'SKU is required.';
        }

        // Name
        $name = trim((string) ($input['name'] ?? ($existing['name'] ?? '')));
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = 'Name must be at most 255 characters.';
        } else {
            $fields['name'] = $name;
        }

        // Description
        $fields['description'] = isset($input['description'])
            ? (string) $input['description']
            : ($existing['description'] ?? null);

        // Category
        $catId = (int) ($input['category_id'] ?? ($existing['category_id'] ?? 0));
        if ($catId <= 0 || !CategoryRepository::findById($catId)) {
            $errors['category_id'] = 'A valid category is required.';
        } else {
            $fields['category_id'] = $catId;
        }

        // Brand
        $fields['brand'] = isset($input['brand'])
            ? (trim((string) $input['brand']) ?: null)
            : ($existing['brand'] ?? null);

        // Price
        $price = $input['price'] ?? ($existing['price'] ?? null);
        if ($price === null || $price === '' || !is_numeric($price) || (float) $price < 0) {
            $errors['price'] = 'Price must be a non-negative number.';
        } else {
            $fields['price'] = number_format((float) $price, 2, '.', '');
        }

        // Stock — only editable before first sync
        if ($isCreate || array_key_exists('stock', $input)) {
            $stock = $input['stock'] ?? 0;
            if (!is_numeric($stock) || (int) $stock < 0) {
                $errors['stock'] = 'Stock must be a non-negative integer.';
            } elseif (!$isCreate && $existing['woocommerce_id'] !== null) {
                // Silently ignore stock changes after first push (read-only)
            } else {
                $fields['stock'] = (int) $stock;
            }
        }

        // Status
        $allowedStatus = ['draft', 'active', 'inactive', 'archived'];
        $status = (string) ($input['status'] ?? ($existing['status'] ?? 'draft'));
        if (!in_array($status, $allowedStatus, true)) {
            $errors['status'] = 'Invalid status.';
        } else {
            $fields['status'] = $status;
        }

        if ($errors) {
            throw new HttpException(422, 'validation_error', 'Validation failed.', $errors);
        }
        return $fields;
    }

    /**
     * Enforce required attributes when status is 'active'.
     * @param list<array{attribute_id:int, value:mixed}> $attrValues
     */
    private static function assertRequiredAttributes(int $categoryId, array $attrValues, string $status): void
    {
        if ($status !== 'active') {
            return;
        }
        $assigned = CategoryRepository::getAttributes($categoryId);
        $valueMap = [];
        foreach ($attrValues as $v) {
            $valueMap[(int) $v['attribute_id']] = $v['value'] ?? null;
        }
        $missing = [];
        foreach ($assigned as $a) {
            if (!(int) $a['required']) {
                continue;
            }
            $val = $valueMap[(int) $a['attribute_id']] ?? null;
            if ($val === null || $val === '') {
                $missing[] = $a['name'];
            }
        }
        // Also type-check provided values
        foreach ($attrValues as $v) {
            $aid = (int) ($v['attribute_id'] ?? 0);
            $attr = AttributeRepository::findById($aid);
            if (!$attr) {
                throw new HttpException(422, 'validation_error', "Attribute $aid not found.");
            }
            $val = $v['value'] ?? null;
            if ($val === null || $val === '') {
                continue;
            }
            match ($attr['data_type']) {
                'number', 'unit' => is_numeric($val) || throw new HttpException(422, 'validation_error', "Attribute \"{$attr['name']}\" must be a number."),
                'boolean' => is_bool($val) || $val === 0 || $val === 1 || $val === '0' || $val === '1' || throw new HttpException(422, 'validation_error', "Attribute \"{$attr['name']}\" must be boolean."),
                'select' => (function () use ($attr, $val) {
                    $opt = AttributeRepository::findOption((int) $val);
                    if (!$opt || (int) $opt['attribute_id'] !== (int) $attr['id']) {
                        throw new HttpException(422, 'validation_error', "Invalid option for attribute \"{$attr['name']}\".");
                    }
                })(),
                default => null,
            };
        }
        if ($missing) {
            throw new HttpException(422, 'validation_error', 'Required attributes are missing.', [
                'required' => $missing,
            ]);
        }
    }

    private static function publicProduct(array $p): array
    {
        // Decode JSON columns for the API response
        if (isset($p['wc_snapshot']) && is_string($p['wc_snapshot'])) {
            $p['wc_snapshot'] = json_decode($p['wc_snapshot'], true);
        }
        if (isset($p['conflict_fields']) && is_string($p['conflict_fields'])) {
            $p['conflict_fields'] = json_decode($p['conflict_fields'], true);
        }
        // Stock is read-only after first push
        $p['stock_editable'] = $p['woocommerce_id'] === null;
        return $p;
    }
}
