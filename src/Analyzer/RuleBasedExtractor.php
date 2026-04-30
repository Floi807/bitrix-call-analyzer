<?php

declare(strict_types=1);

namespace App\Analyzer;

final class RuleBasedExtractor implements AiExtractorInterface
{
    public function __construct(
        private readonly int $defaultQuantity = 1,
        private readonly int $maxProducts = 25
    ) {
    }

    public function extract(string $transcript): array
    {
        $lines = preg_split('/[\r\n\.!\?]+/u', $transcript) ?: [];
        $items = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || mb_strlen($line) < 3) {
                continue;
            }

            $quantity = $this->extractQuantity($line);
            $parameters = $this->extractParameters($line);
            $productName = $this->extractProductName($line, $parameters);

            if ($productName === null) {
                continue;
            }

            $items[] = [
                'name' => $productName,
                'quantity' => $quantity,
                'parameters' => $parameters,
            ];

            if (count($items) >= $this->maxProducts) {
                break;
            }
        }

        return $this->mergeItems($items);
    }

    private function extractQuantity(string $line): float
    {
        if (preg_match('/\b(\d+(?:[\,\.]\d+)?)\s*(шт|штук|ед|уп|упаков(?:ка|ки)|м2|м|кг)?\b/iu', $line, $matches) === 1) {
            return (float) str_replace(',', '.', $matches[1]);
        }

        return (float) $this->defaultQuantity;
    }

    private function extractParameters(string $line): array
    {
        $parameters = [];

        if (preg_match('/цвет\s*[:\-]?\s*([a-zа-я0-9\- ]+)/iu', $line, $matches) === 1) {
            $parameters['цвет'] = trim($matches[1]);
        }

        if (preg_match('/размер\s*[:\-]?\s*([a-zа-я0-9xх\* ]+)/iu', $line, $matches) === 1) {
            $parameters['размер'] = trim($matches[1]);
        }

        if (preg_match('/артикул\s*[:\-]?\s*([a-z0-9\-]+)/iu', $line, $matches) === 1) {
            $parameters['артикул'] = trim($matches[1]);
        }

        return $parameters;
    }

    private function extractProductName(string $line, array $parameters): ?string
    {
        $clean = mb_strtolower($line);
        $clean = preg_replace('/\bнужно\b|\bнужен\b|\bнужна\b|\bзакаж(?:ем|ите|у)\b|\bдобав(?:ь|ьте|ить)\b|\bхотим\b|\bвозьм(?:ем|ите)\b/iu', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\b\d+(?:[\,\.]\d+)?\s*(шт|штук|ед|уп|упаков(?:ка|ки)|м2|м|кг)?\b/iu', ' ', $clean) ?? $clean;

        foreach (array_keys($parameters) as $parameterKey) {
            $clean = preg_replace('/' . preg_quote($parameterKey, '/') . '\s*[:\-]?\s*[a-zа-я0-9xх\* \-]+/iu', ' ', $clean) ?? $clean;
        }

        $clean = preg_replace('/[^a-zа-я0-9 ]+/iu', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\s+/u', ' ', trim($clean)) ?? trim($clean);

        if ($clean === '' || mb_strlen($clean) < 3) {
            return null;
        }

        return $clean;
    }

    private function mergeItems(array $items): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $key = $item['name'] . '|' . json_encode($item['parameters'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!isset($grouped[$key])) {
                $grouped[$key] = $item;
                continue;
            }

            $grouped[$key]['quantity'] += $item['quantity'];
        }

        return array_values($grouped);
    }
}
