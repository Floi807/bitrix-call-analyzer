<?php

declare(strict_types=1);

namespace App\Service;

use App\Bitrix\PortalClient;

final class DealProductUpdater
{
    public function replaceRows(PortalClient $client, int $dealId, array $rows): bool
    {
        $result = $client->call('crm.deal.productrows.set', [
            'id' => $dealId,
            'rows' => $rows,
        ]);

        return $result === true || $result === 1 || $result === '1' || $result === [];
    }
}
