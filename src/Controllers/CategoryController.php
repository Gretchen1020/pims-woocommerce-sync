<?php
declare(strict_types=1);

namespace Pims\Controllers;

use Pims\Http\Request;
use Pims\Http\Response;
use Pims\Services\CatalogService;

final class CategoryController
{
    public static function index(Request $request, array $params): void
    {
        Response::json(['data' => CatalogService::listCategories()]);
    }

    public static function show(Request $request, array $params): void
    {
        Response::json(['data' => CatalogService::getCategory((int) $params['id'])]);
    }

    public static function create(Request $request, array $params): void
    {
        $cat = CatalogService::createCategory($request->json());
        Response::json(['data' => $cat], 201);
    }

    public static function update(Request $request, array $params): void
    {
        $cat = CatalogService::updateCategory((int) $params['id'], $request->json());
        Response::json(['data' => $cat]);
    }

    public static function delete(Request $request, array $params): void
    {
        CatalogService::deleteCategory((int) $params['id']);
        Response::json(['data' => ['deleted' => true]]);
    }

    public static function setAttributes(Request $request, array $params): void
    {
        $body = $request->json();
        $assignments = $body['attributes'] ?? $body;
        if (!is_array($assignments)) {
            $assignments = [];
        }
        $cat = CatalogService::setCategoryAttributes((int) $params['id'], $assignments);
        Response::json(['data' => $cat]);
    }
}
