<?php

declare(strict_types=1);

namespace App\Analyzer;

interface AiExtractorInterface
{
    public function extract(string $transcript): array;
}
