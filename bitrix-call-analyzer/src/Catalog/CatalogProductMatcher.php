<?php

declare(strict_types=1);

namespace App\Catalog;

final class CatalogProductMatcher
{
    public function __construct(
        private readonly array $aliases = []
    ) {
    }

    public function match(array $catalog, string $requestedName): ?array
    {
        $normalizedRequested = $this->normalize($requestedName);
        $aliasCanonical = $this->resolveAlias($normalizedRequested);
        $best = null;
        $bestScore = -1;

        foreach ($catalog as $product) {
            $name = (string) ($product['NAME'] ?? '');
            if ($name === '') {
                continue;
            }

            $normalizedName = $this->normalize($name);
            $score = 0;

            if ($normalizedName === $normalizedRequested) {
                $score = 100;
            } elseif ($aliasCanonical !== null && $normalizedName === $aliasCanonical) {
                $score = 95;
            } elseif (str_contains($normalizedName, $normalizedRequested) || str_contains($normalizedRequested, $normalizedName)) {
                $score = 80;
            } else {
                similar_text($normalizedRequested, $normalizedName, $percent);
                $score = (int) $percent;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $product;
            }
        }

        return $bestScore >= 60 ? $best : null;
    }

    private function resolveAlias(string $normalizedRequested): ?string
    {
        foreach ($this->aliases as $canonical => $variants) {
            $normalizedCanonical = $this->normalize((string) $canonical);
            if ($normalizedRequested === $normalizedCanonical) {
                return $normalizedCanonical;
            }

            foreach ((array) $variants as $variant) {
                if ($normalizedRequested === $this->normalize((string) $variant)) {
                    return $normalizedCanonical;
                }
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-zа-я0-9]+/iu', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return $value;
    }
}
