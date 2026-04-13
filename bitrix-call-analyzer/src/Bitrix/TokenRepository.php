<?php

declare(strict_types=1);

namespace App\Bitrix;

use App\Support\JsonStore;

final class TokenRepository
{
    private JsonStore $store;

    public function __construct(string $storagePath)
    {
        $this->store = new JsonStore(rtrim($storagePath, '/\\') . '/tokens.json');
    }

    public function save(string $memberId, array $payload): void
    {
        $all = $this->store->all();
        $all[$memberId] = $payload;
        $this->store->put($all);
    }

    public function find(string $memberId): ?array
    {
        $all = $this->store->all();
        return $all[$memberId] ?? null;
    }
}
