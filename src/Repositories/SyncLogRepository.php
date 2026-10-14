<?php
declare(strict_types=1);

namespace Pims\Repositories;

use Pims\Database;

final class SyncLogRepository
{
    public static function create(
        ?int $productId,
        string $direction,
        string $outcome,
        ?string $error = null,
        ?int $httpStatus = null
    ): void {
        $stmt = Database::connection()->prepare(
            'INSERT INTO sync_logs (product_id, direction, outcome, error, http_status)
             VALUES (:pid, :dir, :outcome, :error, :http)'
        );
        $stmt->execute([
            ':pid'     => $productId,
            ':dir'     => $direction,
            ':outcome' => $outcome,
            ':error'   => $error,
            ':http'    => $httpStatus,
        ]);
    }

    /**
     * @return array{items:list, total:int, page:int, limit:int}
     */
    public static function list(int $page = 1, int $limit = 50, ?int $productId = null): array
    {
        $page  = max(1, $page);
        $limit = min(100, max(1, $limit));
        $offset = ($page - 1) * $limit;

        $where  = '';
        $params = [];
        if ($productId !== null) {
            $where = 'WHERE product_id = :pid';
            $params[':pid'] = $productId;
        }

        $pdo = Database::connection();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sync_logs $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT * FROM sync_logs $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }
}
