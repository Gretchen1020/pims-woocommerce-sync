<?php
declare(strict_types=1);

namespace Pims\Repositories;

use Pims\Database;

final class AttributeRepository
{
    public static function findAll(): array
    {
        $stmt = Database::connection()->query(
            'SELECT * FROM attributes ORDER BY name ASC'
        );
        $attrs = $stmt->fetchAll();
        foreach ($attrs as &$a) {
            if ($a['data_type'] === 'select') {
                $a['options'] = self::getOptions((int) $a['id']);
            }
        }
        return $attrs;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM attributes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $a = $stmt->fetch() ?: null;
        if ($a && $a['data_type'] === 'select') {
            $a['options'] = self::getOptions((int) $a['id']);
        }
        return $a;
    }

    public static function findByName(string $name): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM attributes WHERE name = :name');
        $stmt->execute([':name' => $name]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $name, string $dataType, ?string $unit = null): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO attributes (name, data_type, unit) VALUES (:name, :type, :unit)'
        );
        $stmt->execute([
            ':name' => $name,
            ':type' => $dataType,
            ':unit' => $unit,
        ]);
        return self::findById((int) $pdo->lastInsertId());
    }

    public static function update(int $id, string $name, ?string $unit = null): ?array
    {
        $stmt = Database::connection()->prepare(
            'UPDATE attributes SET name = :name, unit = :unit WHERE id = :id'
        );
        $stmt->execute([
            ':id'   => $id,
            ':name' => $name,
            ':unit' => $unit,
        ]);
        return self::findById($id);
    }

    public static function delete(int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM attributes WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public static function hasValues(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM product_attributes WHERE attribute_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    public static function isAssignedToCategory(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM category_attributes WHERE attribute_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    public static function setWooCommerceId(int $id, int $wcId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE attributes SET woocommerce_id = :wc_id WHERE id = :id'
        );
        $stmt->execute([':id' => $id, ':wc_id' => $wcId]);
    }

    // ── Options (select type) ──────────────────────────────────────────

    public static function getOptions(int $attributeId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM attribute_options WHERE attribute_id = :aid ORDER BY value ASC'
        );
        $stmt->execute([':aid' => $attributeId]);
        return $stmt->fetchAll();
    }

    public static function findOption(int $optionId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM attribute_options WHERE id = :id'
        );
        $stmt->execute([':id' => $optionId]);
        return $stmt->fetch() ?: null;
    }

    public static function addOption(int $attributeId, string $value): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO attribute_options (attribute_id, value) VALUES (:aid, :val)'
        );
        $stmt->execute([':aid' => $attributeId, ':val' => $value]);
        $id = (int) $pdo->lastInsertId();
        return self::findOption($id);
    }

    public static function updateOption(int $optionId, string $value): ?array
    {
        $stmt = Database::connection()->prepare(
            'UPDATE attribute_options SET value = :val WHERE id = :id'
        );
        $stmt->execute([':id' => $optionId, ':val' => $value]);
        return self::findOption($optionId);
    }

    public static function deleteOption(int $optionId): bool
    {
        $stmt = Database::connection()->prepare(
            'DELETE FROM attribute_options WHERE id = :id'
        );
        $stmt->execute([':id' => $optionId]);
        return $stmt->rowCount() > 0;
    }

    public static function optionHasValues(int $optionId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM product_attributes WHERE option_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $optionId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function setOptionTermId(int $optionId, int $termId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE attribute_options SET woocommerce_term_id = :tid WHERE id = :id'
        );
        $stmt->execute([':id' => $optionId, ':tid' => $termId]);
    }
}
