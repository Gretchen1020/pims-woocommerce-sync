<?php
declare(strict_types=1);

namespace Pims\Controllers;

use Pims\Http\Request;
use Pims\Http\Response;
use Pims\Services\SyncService;

final class SyncController
{
    public static function sync(Request $request, array $params): void
    {
        $svc = new SyncService();
        $product = $svc->syncProduct((int) $params['id']);
        // Decode JSON fields for response
        if (isset($product['wc_snapshot']) && is_string($product['wc_snapshot'])) {
            $product['wc_snapshot'] = json_decode($product['wc_snapshot'], true);
        }
        if (isset($product['conflict_fields']) && is_string($product['conflict_fields'])) {
            $product['conflict_fields'] = json_decode($product['conflict_fields'], true);
        }
        Response::json(['data' => $product]);
    }

    public static function status(Request $request, array $params): void
    {
        $svc = new SyncService();
        Response::json(['data' => $svc->getSyncStatus((int) $params['id'])]);
    }

    public static function resolve(Request $request, array $params): void
    {
        $svc = new SyncService();
        $product = $svc->resolveConflict((int) $params['id'], $request->json());
        if (isset($product['wc_snapshot']) && is_string($product['wc_snapshot'])) {
            $product['wc_snapshot'] = json_decode($product['wc_snapshot'], true);
        }
        if (isset($product['conflict_fields']) && is_string($product['conflict_fields'])) {
            $product['conflict_fields'] = json_decode($product['conflict_fields'], true);
        }
        Response::json(['data' => $product]);
    }

    public static function refreshStock(Request $request, array $params): void
    {
        $svc = new SyncService();
        $product = $svc->refreshStock((int) $params['id']);
        Response::json(['data' => $product]);
    }

    public static function import(Request $request, array $params): void
    {
        $svc = new SyncService();
        $stats = $svc->import();
        Response::json(['data' => $stats]);
    }

    public static function logs(Request $request, array $params): void
    {
        $svc = new SyncService();
        $page = isset($request->query['page']) ? (int) $request->query['page'] : 1;
        $limit = isset($request->query['limit']) ? (int) $request->query['limit'] : 50;
        $productId = isset($request->query['product_id']) ? (int) $request->query['product_id'] : null;
        $result = $svc->listLogs($page, $limit, $productId);
        Response::json(['data' => $result['items'], 'meta' => [
            'total' => $result['total'],
            'page'  => $result['page'],
            'limit' => $result['limit'],
        ]]);
    }
}
