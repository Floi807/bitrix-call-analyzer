<?php

return [
    'app_url' => 'https://8a01-216-106-187-181.ngrok-free.app',
    'client_id' => 'local.XXXXXXXXXXXXXXXXXXXXXXXX',
    'client_secret' => 'replace-me',
    'webhook_url' => 'https://b24-pplyid.bitrix24.ru/rest/1/qytinepa4bq2jdmw',
    'verify_ssl' => false,
    'connect_timeout' => 5,
    'request_timeout' => 12,
    'scope' => 'crm,catalog,placement',
    'storage_path' => dirname(__DIR__) . '/data',
    'log_file' => dirname(__DIR__) . '/data/app.log',
    'transcript_sources' => [
        'deal_fields' => [
            'UF_CRM_CALL_TRANSCRIPT',
        ],
        'activity_types' => [2],
    ],
    'catalog' => [
        'aliases' => [
            'окно стандарт' => ['стандартное окно'],
            'дверь входная' => ['входная дверь'],
        ],
    ],
    'parser' => [
        'default_quantity' => 1,
        'max_products_per_request' => 25,
    ],
];
