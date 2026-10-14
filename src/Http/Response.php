<?php
declare(strict_types=1);

namespace Pims\Http;

final class Response
{
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(int $status, string $code, string $message, ?array $fields = null): void
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== null) {
            $error['fields'] = $fields;
        }
        self::json(['error' => $error], $status);
    }
}
