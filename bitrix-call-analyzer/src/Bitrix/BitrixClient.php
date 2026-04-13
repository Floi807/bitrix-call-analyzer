<?php

declare(strict_types=1);

namespace App\Bitrix;

use App\Support\Config;

final class BitrixClient
{
    public function __construct(
        private readonly Config $config,
        private readonly TokenRepository $tokens
    ) {
    }

    public function forPortal(string $memberId): PortalClient
    {
        if ($memberId === 'webhook') {
            return $this->default();
        }

        $token = $this->tokens->find($memberId);
        if ($token === null) {
            throw new BitrixException('Portal tokens not found.');
        }

        return new PortalClient($this->config, $this->tokens, $memberId, $token);
    }

    public function fromAuthPayload(array $auth): PortalClient
    {
        if (empty($auth['member_id']) || empty($auth['domain']) || empty($auth['access_token'])) {
            throw new BitrixException('Invalid auth payload.');
        }

        return new PortalClient($this->config, $this->tokens, (string) $auth['member_id'], [
            'member_id' => (string) $auth['member_id'],
            'domain' => (string) $auth['domain'],
            'access_token' => (string) $auth['access_token'],
            'refresh_token' => (string) ($auth['refresh_token'] ?? ''),
            'expires_at' => time() + (int) ($auth['expires_in'] ?? 3600),
        ]);
    }

    public function default(): PortalClient
    {
        $webhookUrl = trim((string) $this->config->get('webhook_url', ''));
        if ($webhookUrl !== '') {
            return PortalClient::fromWebhook($this->config, $webhookUrl);
        }

        throw new BitrixException('No default Bitrix24 connection configured. Set webhook_url or install the app.');
    }
}
