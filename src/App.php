<?php

declare(strict_types=1);

namespace App;

use App\Analyzer\RuleBasedExtractor;
use App\Analyzer\TranscriptAnalyzer;
use App\Bitrix\BitrixClient;
use App\Bitrix\StateRepository;
use App\Bitrix\TokenRepository;
use App\Catalog\CatalogProductMatcher;
use App\Http\Request;
use App\Http\Response;
use App\Service\CatalogProvider;
use App\Service\DealProcessor;
use App\Service\DealProductUpdater;
use App\Service\TranscriptProvider;
use App\Support\Config;
use App\Support\Logger;
use Throwable;

final class App
{
    private Config $config;
    private Logger $logger;
    private TokenRepository $tokens;
    private BitrixClient $bitrix;
    private DealProcessor $processor;

    public function __construct()
    {
        $this->config = new Config();
        $this->logger = new Logger((string) $this->config->get('log_file'));
        $this->tokens = new TokenRepository((string) $this->config->get('storage_path'));
        $this->bitrix = new BitrixClient($this->config, $this->tokens);

        $extractor = new RuleBasedExtractor(
            (int) $this->config->get('parser.default_quantity', 1),
            (int) $this->config->get('parser.max_products_per_request', 25)
        );

        $matcher = new CatalogProductMatcher((array) $this->config->get('catalog.aliases', []));

        $this->processor = new DealProcessor(
            new TranscriptProvider($this->config),
            new CatalogProvider(),
            new TranscriptAnalyzer($extractor, $matcher),
            new DealProductUpdater(),
            new StateRepository((string) $this->config->get('storage_path')),
            $this->logger
        );
    }

    public function run(Request $request): void
    {
        try {
            $path = rtrim($request->path(), '/');
            $path = $path === '' ? '/' : $path;
            $action = (string) $request->input('action', '');

            if ($this->looksLikeInstall($request)) {
                $this->handleInstall($request);
                return;
            }

            if ($action === 'event') {
                $this->handleEvent($request);
                return;
            }

            if ($action === 'ui') {
                $this->renderPlacement();
                return;
            }

            if ($action === 'analyze') {
                $this->handleManualAnalyze($request);
                return;
            }

            if ($action === 'inspect') {
                $this->handleInspect($request);
                return;
            }

            if ($action === 'manual-sync') {
                $this->handleManualSync($request);
                return;
            }

            if ($path === '/' || str_ends_with($path, '/index.php')) {
                $this->renderLanding();
                return;
            }

            Response::json(['error' => 'Not found'], 404);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
            Response::json(['error' => $e->getMessage()], 500);
        }
    }

    private function looksLikeInstall(Request $request): bool
    {
        return isset($request->request['event']) && in_array($request->request['event'], ['ONAPPINSTALL', 'ONAPPUPDATE'], true);
    }

    private function handleInstall(Request $request): void
    {
        $auth = (array) $request->input('auth', []);
        $memberId = (string) ($auth['member_id'] ?? '');

        if ($memberId === '') {
            Response::json(['error' => 'member_id is required'], 400);
            return;
        }

        $this->tokens->save($memberId, [
            'member_id' => $memberId,
            'domain' => (string) ($auth['domain'] ?? ''),
            'access_token' => (string) ($auth['access_token'] ?? ''),
            'refresh_token' => (string) ($auth['refresh_token'] ?? ''),
            'expires_at' => time() + (int) ($auth['expires_in'] ?? 3600),
        ]);

        $client = $this->bitrix->fromAuthPayload($auth);
        $baseUrl = (string) $this->config->get('app_url');
        $eventHandler = $baseUrl . '?action=event';
        $placementHandler = $baseUrl . '?action=ui';

        $client->call('event.bind', [
            'event' => 'onCrmDealUpdate',
            'handler' => $eventHandler,
        ]);

        $client->call('placement.bind', [
            'PLACEMENT' => 'CRM_DEAL_DETAIL_TAB',
            'HANDLER' => $placementHandler,
            'TITLE' => 'Call Analyzer',
            'DESCRIPTION' => 'Analyze call transcript and update deal product rows',
        ]);

        Response::json([
            'status' => 'installed',
            'event_handler' => $eventHandler,
            'placement_handler' => $placementHandler,
        ]);
    }

    private function handleEvent(Request $request): void
    {
        $auth = (array) $request->input('auth', []);
        $data = (array) $request->input('data', []);
        $fields = (array) ($data['FIELDS'] ?? []);
        $dealId = (int) ($fields['ID'] ?? 0);

        if ($dealId <= 0) {
            Response::json(['status' => 'ignored', 'reason' => 'Deal id missing']);
            return;
        }

        $client = $this->bitrix->fromAuthPayload($auth);
        Response::json($this->processor->process($client, $dealId, false));
    }

    private function handleManualAnalyze(Request $request): void
    {
        $dealId = (int) $request->input('deal_id', 0);
        $auth = (array) $request->input('auth', []);

        if ($dealId <= 0) {
            Response::json(['error' => 'deal_id is required'], 400);
            return;
        }

        $client = $auth !== [] ? $this->bitrix->fromAuthPayload($auth) : $this->bitrix->default();
        Response::json($this->processor->process($client, $dealId, true));
    }

    private function handleInspect(Request $request): void
    {
        $dealId = (int) $request->input('deal_id', 0);
        $auth = (array) $request->input('auth', []);

        if ($dealId <= 0) {
            Response::json(['error' => 'deal_id is required'], 400);
            return;
        }

        $client = $auth !== [] ? $this->bitrix->fromAuthPayload($auth) : $this->bitrix->default();
        Response::json($this->processor->preview($client, $dealId));
    }

    private function handleManualSync(Request $request): void
    {
        $memberId = (string) $request->input('member_id', '');
        $dealId = (int) $request->input('deal_id', 0);
        $transcript = trim((string) $request->input('transcript', ''));

        if ($memberId === '' || $dealId <= 0 || $transcript === '') {
            Response::json(['error' => 'member_id, deal_id and transcript are required'], 400);
            return;
        }

        $client = $this->bitrix->forPortal($memberId);
        $client->call('crm.deal.update', [
            'id' => $dealId,
            'fields' => ['COMMENTS' => $transcript],
        ]);

        Response::json($this->processor->process($client, $dealId, true));
    }

    private function renderLanding(): void
    {
        Response::html(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Bitrix24 Call Analyzer</title>
    <style>
        :root{color-scheme:light;background:#eef4f7;color:#123}
        *{box-sizing:border-box}
        body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:linear-gradient(135deg,#edf6ff 0%,#f7fbef 100%);color:#183153}
        .page{max-width:1080px;margin:0 auto;padding:32px 20px 48px}
        .hero{display:grid;grid-template-columns:1.2fr .8fr;gap:20px;align-items:stretch}
        .card{background:rgba(255,255,255,.92);border:1px solid rgba(24,49,83,.12);border-radius:22px;padding:24px;box-shadow:0 18px 45px rgba(24,49,83,.08)}
        h1,h2{margin:0 0 12px}
        p{line-height:1.55;margin:0 0 12px}
        .muted{color:#4c627d}
        .pill{display:inline-block;padding:8px 12px;border-radius:999px;background:#dff4e8;color:#17603a;font-weight:700;font-size:12px;letter-spacing:.04em;text-transform:uppercase}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-top:20px}
        label{display:block;font-size:13px;font-weight:700;margin:0 0 8px}
        input,textarea{width:100%;padding:12px 14px;border:1px solid #cfdce8;border-radius:14px;font:inherit;background:#fff}
        textarea{min-height:118px;resize:vertical}
        button{border:0;background:#0f8d61;color:#fff;padding:12px 16px;border-radius:14px;font-weight:700;cursor:pointer}
        button.alt{background:#1f4fd1}
        .actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}
        pre{margin:0;white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#d9e2f0;padding:16px;border-radius:16px;min-height:220px}
        ul{margin:12px 0 0;padding-left:18px}
        code{background:#edf3f8;padding:2px 6px;border-radius:6px}
        @media (max-width:900px){.hero,.grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <div class="page">
        <div class="hero">
            <section class="card">
                <span class="pill">Режим Webhook Готов</span>
                <h1>Bitrix24 Call Analyzer</h1>
                <p>Приложение берёт сделку, ищет расшифровку звонка, извлекает товары, количество и параметры, а затем записывает найденные позиции в товарные строки сделки Bitrix24.</p>
                <p class="muted">Ниже можно вручную запустить анализ через REST webhook уже сейчас. Режим установки приложения через OAuth тоже поддерживается, но webhook-режим позволяет быстрее всего получить рабочий результат на вашем портале.</p>
                <ul>
                    <li>Читает расшифровку из комментариев сделки, настроенных полей или связанных активностей звонка.</li>
                    <li>Сопоставляет найденные названия с каталогом Bitrix24.</li>
                    <li>Автоматически записывает товарные строки обратно в сделку.</li>
                </ul>
            </section>
            <section class="card">
                <h2>Статус Подключения</h2>
                <p><strong>URL приложения:</strong><br><code>{$this->escape((string) $this->config->get('app_url'))}</code></p>
                <p><strong>Webhook настроен:</strong> {$this->escape($this->config->get('webhook_url', '') !== '' ? 'да' : 'нет')}</p>
                <p><strong>Точка установки:</strong><br><code>?event=ONAPPINSTALL</code> на этом URL</p>
                <p class="muted">Если webhook-режим включён, вы можете запускать анализ сделки сразу, не дожидаясь полного сценария установки приложения.</p>
            </section>
        </div>

        <div class="grid">
            <section class="card">
                <h2>Запуск Анализа</h2>
                <label for="deal-id">ID сделки</label>
                <input id="deal-id" type="number" min="1" placeholder="Введите ID сделки Bitrix24">
                <div class="actions">
                    <button id="inspect" class="alt" type="button">Проверить Расшифровку</button>
                    <button id="analyze" type="button">Проанализировать И Обновить Сделку</button>
                </div>
                <p class="muted" style="margin-top:14px">Проверка показывает найденную расшифровку и предварительный результат. Анализ записывает товарные строки в сделку.</p>
            </section>

            <section class="card">
                <h2>Ручная Загрузка Расшифровки</h2>
                <label for="manual-deal-id">ID сделки</label>
                <input id="manual-deal-id" type="number" min="1" placeholder="Введите ID сделки">
                <label for="manual-transcript" style="margin-top:14px">Текст расшифровки</label>
                <textarea id="manual-transcript" placeholder="Вставьте расшифровку сюда, если она ещё не хранится в Bitrix24"></textarea>
                <div class="actions">
                    <button id="manual-sync" type="button">Сохранить Расшифровку И Запустить Анализ</button>
                </div>
            </section>
        </div>

        <section class="card" style="margin-top:20px">
            <h2>Результат</h2>
            <pre id="result">Готово. Введите ID сделки и запустите проверку или анализ.</pre>
        </section>
    </div>
    <script>
        const result = document.getElementById('result');
        const dealId = document.getElementById('deal-id');
        const manualDealId = document.getElementById('manual-deal-id');
        const manualTranscript = document.getElementById('manual-transcript');

        function show(payload) {
            result.textContent = typeof payload === 'string' ? payload : JSON.stringify(payload, null, 2);
        }

        async function post(action, payload) {
            show('Выполняется запрос...');
            const response = await fetch('?action=' + action, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });

            const data = await response.json();
            show(data);
        }

        document.getElementById('inspect').addEventListener('click', function () {
            post('inspect', {deal_id: Number(dealId.value || 0)});
        });

        document.getElementById('analyze').addEventListener('click', function () {
            post('analyze', {deal_id: Number(dealId.value || 0)});
        });

        document.getElementById('manual-sync').addEventListener('click', function () {
            post('manual-sync', {
                member_id: 'webhook',
                deal_id: Number(manualDealId.value || 0),
                transcript: manualTranscript.value
            });
        });
    </script>
</body>
</html>
HTML);
    }

    private function renderPlacement(): void
    {
        Response::html(<<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Call Analyzer</title>
    <script src="https://api.bitrix24.com/api/v1/"></script>
    <style>
        body{font-family:Segoe UI,Arial,sans-serif;margin:0;padding:18px;background:#f6f8fb;color:#1f2937}
        .wrap{background:#fff;border-radius:16px;padding:20px;box-shadow:0 10px 30px rgba(15,23,42,.08)}
        button{border:0;background:#0ea5e9;color:#fff;padding:12px 16px;border-radius:12px;font-weight:600;cursor:pointer}
        pre{white-space:pre-wrap;background:#0f172a;color:#e5e7eb;padding:16px;border-radius:12px;min-height:120px}
    </style>
</head>
<body>
    <div class="wrap">
        <h2>Call Transcript Analyzer</h2>
        <p>Run transcript analysis for the current deal and update product rows automatically.</p>
        <button id="run">Analyze Transcript</button>
        <pre id="result">Waiting to start...</pre>
    </div>
    <script>
        const result = document.getElementById('result');
        const button = document.getElementById('run');
        BX24.init(function () {
            button.addEventListener('click', function () {
                const placement = BX24.placement.info();
                const auth = BX24.getAuth();
                const dealId = Number((placement.options || {}).ID || 0);
                result.textContent = 'Analysis in progress...';
                fetch(window.location.pathname + '?action=analyze', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({deal_id: dealId, auth: auth})
                })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    result.textContent = JSON.stringify(payload, null, 2);
                    if (payload.status === 'updated') {
                        BX24.reloadWindow();
                    }
                })
                .catch(function (error) {
                    result.textContent = String(error);
                });
            });
        });
    </script>
</body>
</html>
HTML);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
