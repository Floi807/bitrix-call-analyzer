<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    public function __construct(
        public readonly array $query,
        public readonly array $request,
        public readonly array $server,
        public readonly string $rawBody
    ) {
    }

    public static function capture(): self
    {
        return new self($_GET, $_POST, $_SERVER, file_get_contents('php://input') ?: '');
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        return parse_url($uri, PHP_URL_PATH) ?: '/';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->request)) {
            return $this->request[$key];
        }

        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }

        $json = json_decode($this->rawBody, true);
        if (is_array($json) && array_key_exists($key, $json)) {
            return $json[$key];
        }

        return $default;
    }
}
