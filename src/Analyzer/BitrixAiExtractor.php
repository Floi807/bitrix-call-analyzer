<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Bitrix\PortalClient;

final class BitrixAiExtractor implements AiExtractorInterface
{
    private readonly PortalClient $client;

    public function __construct(PortalClient $client)
    {
        $this->client = $client;
    }

    public function extract(string $transcript): array
    {
        // Вызываем Bitrix AI через REST API для анализа транскрипции
        $response = $this->client->call('ai.predict', [
            'model' => 'bitrix.copilot',
            'prompt' => $this->createPrompt($transcript)
        ]);

        $text = $response['result']['text'] ?? '';

        // Парсим ответ AI и извлекаем товары, количество и параметры
        return $this->parseAiResponse($text);
    }

    private function createPrompt(string $transcript): string
    {
        return "Проанализируй расшифровку звонка и выдели упомянутые товары, их количество и параметры (цвет, размер, артикул и т.д.).
Верни результат в формате JSON массива объектов с полями: name (название товара), quantity (количество), parameters (ассоциативный массив параметров).

Расшифровка:\n$transcript";
    }

    private function parseAiResponse(string $text): array
    {
        // Ищем JSON в ответе AI
        if (preg_match('/\[\s*\{.*\}\s*\]/is', $text, $matches)) {
            $json = $matches[0];
            $data = json_decode($json, true);

            if (is_array($data)) {
                return array_map(function ($item) {
                    return [
                        'name' => $item['name'] ?? '',
                        'quantity' => $item['quantity'] ?? 1,
                        'parameters' => $item['parameters'] ?? []
                    ];
                }, $data);
            }
        }

        // Если JSON не найден, возвращаем пустой массив
        return [];
    }
}