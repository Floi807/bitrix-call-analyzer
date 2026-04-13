<?php

declare(strict_types=1);

namespace App\Bitrix;

use App\Support\Config;

final class PortalClient
{
    private ?string $webhookBaseUrl = null;

    public function __construct(
        private readonly Config $config,
        private readonly TokenRepository $tokens,
        private readonly string $memberId,
        private array $token
    ) {
    }

    public function memberId(): string
    {
        return $this->memberId;
    }

    public static function fromWebhook(Config $config, string $webhookUrl): self
    {
        $client = new self($config, new TokenRepository((string) $config->get('storage_path')), 'webhook', [
            'member_id' => 'webhook',
            'domain' => '',
            'access_token' => '',
            'refresh_token' => '',
            'expires_at' => PHP_INT_MAX,
        ]);
        $client->webhookBaseUrl = rtrim($webhookUrl, '/');

        return $client;
    }

    public function call(string $method, array $params = []): array
    {
        if ($this->webhookBaseUrl !== null) {
            $response = $this->request($this->webhookBaseUrl . '/' . $method, $params);

            if (isset($response['error'])) {
                throw new BitrixException($response['error_description'] ?? $response['error']);
            }

            return $response['result'] ?? $response;
        }

        if ($this->isExpired() && !empty($this->token['refresh_token'])) {
            $this->refresh();
        }

        $url = sprintf('https://%s/rest/%s', $this->token['domain'], $method);
        $params['auth'] = $this->token['access_token'];
        $response = $this->request($url, $params);

        if (isset($response['error']) && $response['error'] === 'expired_token' && !empty($this->token['refresh_token'])) {
            $this->refresh();
            $params['auth'] = $this->token['access_token'];
            $response = $this->request($url, $params);
        }

        if (isset($response['error'])) {
            throw new BitrixException($response['error_description'] ?? $response['error']);
        }

        return $response['result'] ?? $response;
    }

    public function listAll(string $method, array $params = []): array
    {
        $start = 0;
        $items = [];

        do {
            $chunk = $this->call($method, array_merge($params, ['start' => $start]));

            if (isset($chunk['items']) && is_array($chunk['items'])) {
                $pageItems = $chunk['items'];
            } elseif (is_array($chunk) && array_is_list($chunk)) {
                $pageItems = $chunk;
            } else {
                $pageItems = [];
            }

            $items = array_merge($items, $pageItems);
            $next = is_array($chunk) ? ($chunk['next'] ?? null) : null;
            $start = is_numeric($next) ? (int) $next : null;
        } while ($start !== null);

        return $items;
    }

    private function isExpired(): bool
    {
        return (int) ($this->token['expires_at'] ?? 0) <= time() + 30;
    }

    private function refresh(): void
    {
        $url = sprintf(
            'https://oauth.bitrix.info/oauth/token/?grant_type=refresh_token&client_id=%s&client_secret=%s&refresh_token=%s',
            rawurlencode((string) $this->config->get('client_id')),
            rawurlencode((string) $this->config->get('client_secret')),
            rawurlencode((string) $this->token['refresh_token'])
        );

        $payload = $this->request($url, [], false);
        if (empty($payload['access_token'])) {
            throw new BitrixException('Unable to refresh access token.');
        }

        $this->token['access_token'] = $payload['access_token'];
        $this->token['refresh_token'] = $payload['refresh_token'] ?? $this->token['refresh_token'];
        $this->token['expires_at'] = time() + (int) ($payload['expires_in'] ?? 3600);
        $this->tokens->save($this->memberId, $this->token);
    }

    private function request(string $url, array $params = [], bool $post = true): array
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new BitrixException('Unable to initialize curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => (bool) $this->config->get('verify_ssl', true),
            CURLOPT_SSL_VERIFYHOST => (bool) $this->config->get('verify_ssl', true) ? 2 : 0,
        ]);

        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new BitrixException($error ?: 'HTTP request failed.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new BitrixException('Invalid JSON response: ' . $raw);
        }

        if ($code >= 400 && !isset($decoded['error'])) {
            throw new BitrixException('Bitrix24 HTTP error ' . $code);
        }

        return $decoded;
    }
}
