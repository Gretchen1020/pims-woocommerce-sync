<?php
declare(strict_types=1);

namespace Pims\Repositories;

use Pims\Database;
use PDO;

final class CategoryRepository
{
    public static function findAll(): array
    {
        $stmt = Database::connection()->query(
            'SELECT * FROM categories ORDER BY name ASC'
        );
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM categories WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByName(string $name): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM categories WHERE name = :name');
        $stmt->execute([':name' => $name]);
        return $stmt->fetch() ?: null;
    }

    public static function create(string $name, ?string $description): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO categories (name, description) VALUES (:name, :description)'
        );
        $stmt->execute([
            ':name'        => $name,
            ':description' => $description,
        ]);
        return self::findById((int) $pdo->lastInsertId());
    }

    public static function update(int $id, string $name, ?string $description): ?array
    {
        $stmt = Database::connection()->prepare(
            'UPDATE categories SET name = :name, description = :description WHERE id = :id'
        );
        $stmt->execute([
            ':id'          => $id,
            ':name'        => $name,
            ':description' => $description,
        ]);
        return self::findById($id);
    }

    public static function delete(int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public static function productCount(int $id): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM products WHERE category_id = :id'
        );
        $stmt->execute([':id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    public static function setWooCommerceId(int $id, int $wcId): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE categories SET woocommerce_id = :wc_id WHERE id = :id'
        );
        $stmt->execute([':id' => $id, ':wc_id' => $wcId]);
    }

    /** @return list<array{attribute_id:int, required:int, name:string, data_type:string, unit:?string}> */
    public static function getAttributes(int $categoryId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ca.attribute_id, ca.required, a.name, a.data_type, a.unit, a.woocommerce_id
             FROM category_attributes ca
             JOIN attributes a ON a.id = ca.attribute_id
             WHERE ca.category_id = :cid
             ORDER BY a.name ASC'
        );
        $stmt->execute([':cid' => $categoryId]);
        return $stmt->fetchAll();
    }

    /** Replace the full set of attributes assigned to a category. */
    public static function setAttributes(int $categoryId, array $assignments): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM category_attributes WHERE category_id = :cid');
            $del->execute([':cid' => $categoryId]);

            $ins = $pdo->prepare(
                'INSERT INTO category_attributes (category_id, attribute_id, required)
                 VALUES (:cid, :aid, :req)'
            );
            foreach ($assignments as $row) {
                $ins->execute([
                    ':cid' => $categoryId,
                    ':aid' => (int) $row['attribute_id'],
                    ':req' => !empty($row['required']) ? 1 : 0,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
