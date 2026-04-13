<?php

declare(strict_types=1);

namespace App\Service;

use App\Bitrix\PortalClient;

final class CatalogProvider
{
    public function getProducts(PortalClient $client): array
    {
        try {
            $result = $client->listAll('catalog.product.list', [
                'select' => ['ID', 'NAME', 'PRICE', 'PRICE_BRUTTO'],
                'filter' => ['ACTIVE' => 'Y'],
                'order' => ['ID' => 'ASC'],
            ]);

            if ($result !== []) {
                return $result;
            }
        } catch (\Throwable) {
        }

        try {
            return $client->listAll('crm.product.list', [
                'select' => ['ID', 'NAME', 'PRICE'],
                'filter' => ['ACTIVE' => 'Y'],
                'order' => ['ID' => 'ASC'],
            ]);
        } catch (\Throwable) {
            return [];
        }
    }
}
