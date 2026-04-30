<?php

declare(strict_types=1);

namespace App\Bitrix;

use App\Support\JsonStore;

final class StateRepository
{
    private JsonStore $store;

    public function __construct(string $storagePath)
    {
        $this->store = new JsonStore(rtrim($storagePath, '/\\') . '/state.json');
    }

    public function getDealHash(string $memberId, int $dealId): ?string
    {
        $all = $this->store->all();
        return $all[$memberId]['deals'][$dealId]['transcript_hash'] ?? null;
    }

    public function putDealState(string $memberId, int $dealId, array $payload): void
    {
        $all = $this->store->all();
        $all[$memberId]['deals'][$dealId] = array_merge($all[$memberId]['deals'][$dealId] ?? [], $payload);
        $this->store->put($all);
    }
}
