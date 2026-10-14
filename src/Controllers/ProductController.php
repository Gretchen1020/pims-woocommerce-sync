<?php
declare(strict_types=1);

namespace Pims\Controllers;

use Pims\Http\Request;
use Pims\Http\Response;
use Pims\Services\CatalogService;

final class ProductController
{
    public static function index(Request $request, array $params): void
    {
        $filters = [
            'search'            => $request->query['search'] ?? null,
            'category_id'       => isset($request->query['category_id']) ? (int) $request->query['category_id'] : null,
            'brand'             => $request->query['brand'] ?? null,
            'status'            => $request->query['status'] ?? null,
            'sync_status'       => $request->query['sync_status'] ?? null,
            'include_archived'  => !empty($request->query['include_archived']),
            'page'              => isset($request->query['page']) ? (int) $request->query['page'] : 1,
            'limit'             => isset($request->query['limit']) ? (int) $request->query['limit'] : 20,
        ];
        $result = CatalogService::listProducts($filters);
        Response::json(['data' => $result['items'], 'meta' => [
            'total' => $result['total'],
            'page'  => $result['page'],
            'limit' => $result['limit'],
        ]]);
    }

    public static function show(Request $request, array $params): void
    {
        Response::json(['data' => CatalogService::getProduct((int) $params['id'])]);
    }

    public static function create(Request $request, array $params): void
    {
        $product = CatalogService::createProduct($request->json());
        Response::json(['data' => $product], 201);
    }

    public static function update(Request $request, array $params): void
    {
        $product = CatalogService::updateProduct((int) $params['id'], $request->json());
        Response::json(['data' => $product]);
    }

    public static function delete(Request $request, array $params): void
    {
        $result = CatalogService::deleteProduct((int) $params['id']);
        Response::json(['data' => $result]);
    }

    public static function restore(Request $request, array $params): void
    {
        $product = CatalogService::restoreProduct((int) $params['id']);
        Response::json(['data' => $product]);
    }
}
