<?php
declare(strict_types=1);

namespace Pims\Controllers;

use Pims\Http\Request;
use Pims\Http\Response;
use Pims\Services\AttributeService;

final class AttributeController
{
    public static function index(Request $request, array $params): void
    {
        Response::json(['data' => AttributeService::list()]);
    }

    public static function show(Request $request, array $params): void
    {
        Response::json(['data' => AttributeService::get((int) $params['id'])]);
    }

    public static function create(Request $request, array $params): void
    {
        $attr = AttributeService::create($request->json());
        Response::json(['data' => $attr], 201);
    }

    public static function update(Request $request, array $params): void
    {
        $attr = AttributeService::update((int) $params['id'], $request->json());
        Response::json(['data' => $attr]);
    }

    public static function delete(Request $request, array $params): void
    {
        AttributeService::delete((int) $params['id']);
        Response::json(['data' => ['deleted' => true]]);
    }

    public static function addOption(Request $request, array $params): void
    {
        $opt = AttributeService::addOption((int) $params['id'], $request->json());
        Response::json(['data' => $opt], 201);
    }

    public static function updateOption(Request $request, array $params): void
    {
        $opt = AttributeService::updateOption((int) $params['id'], (int) $params['optionId'], $request->json());
        Response::json(['data' => $opt]);
    }

    public static function deleteOption(Request $request, array $params): void
    {
        AttributeService::deleteOption((int) $params['id'], (int) $params['optionId']);
        Response::json(['data' => ['deleted' => true]]);
    }
}
