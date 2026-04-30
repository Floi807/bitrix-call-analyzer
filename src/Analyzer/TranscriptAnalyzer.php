<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Catalog\CatalogProductMatcher;

final class TranscriptAnalyzer
{
    public function __construct(
        private readonly AiExtractorInterface $extractor,
        private readonly CatalogProductMatcher $matcher
    ) {
    }

    public function analyze(string $transcript, array $catalog): array
    {
        $extracted = $this->extractor->extract($transcript);
        $rows = [];

        foreach ($extracted as $item) {
            $product = $this->matcher->match($catalog, $item['name']);
            if ($product === null) {
                continue;
            }

            $productName = (string) ($product['NAME'] ?? $item['name']);
            if ($item['parameters'] !== []) {
                $pairs = [];
                foreach ($item['parameters'] as $key => $value) {
                    $pairs[] = $key . ': ' . $value;
                }
                $productName .= ' (' . implode('; ', $pairs) . ')';
            }

            $row = [
                'PRODUCT_ID' => (string) ($product['ID'] ?? ''),
                'PRODUCT_NAME' => $productName,
                'QUANTITY' => (string) $item['quantity'],
            ];

            $price = $product['PRICE'] ?? $product['PRICE_BRUTTO'] ?? null;
            if ($price !== null && $price !== '') {
                $row['PRICE'] = (string) $price;
                $row['CUSTOMIZED'] = 'Y';
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
