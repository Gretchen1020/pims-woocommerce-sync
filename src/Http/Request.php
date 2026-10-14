<?php
declare(strict_types=1);

namespace Pims\Http;

final class Request
{
    private ?array $json = null;

    /** @param array<string,string> $query */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
    ) {}

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/') ?: '/';
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($v) || is_numeric($v)) {
                $query[(string) $k] = (string) $v;
            }
        }
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $query);
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    /** Decoded JSON body. Empty body gives []. Invalid JSON gives a 400. */
    public function json(): array
    {
        if ($this->json === null) {
            $raw = file_get_contents('php://input');
            if ($raw === false || trim($raw) === '') {
                $this->json = [];
            } else {
                $data = json_decode($raw, true);
                if (!is_array($data)) {
                    throw new HttpException(400, 'invalid_json', 'Request body must be a JSON object.');
                }
                $this->json = $data;
            }
        }
        return $this->json;
    }
}
