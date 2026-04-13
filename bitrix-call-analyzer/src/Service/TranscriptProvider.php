<?php

declare(strict_types=1);

namespace App\Service;

use App\Bitrix\PortalClient;
use App\Support\Config;

final class TranscriptProvider
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function getForDeal(PortalClient $client, int $dealId): ?string
    {
        $deal = $client->call('crm.deal.get', ['id' => $dealId]);
        $fromDeal = $this->extractFromDealFields($deal);
        if ($fromDeal !== null) {
            return $fromDeal;
        }

        $activityTypes = (array) $this->config->get('transcript_sources.activity_types', [2]);
        $typeFilter = count($activityTypes) === 1 ? $activityTypes[0] : $activityTypes;

        $activities = $client->call('crm.activity.list', [
            'filter' => [
                'OWNER_TYPE_ID' => 2,
                'OWNER_ID' => $dealId,
                'TYPE_ID' => $typeFilter,
            ],
            'select' => ['ID', 'TYPE_ID', 'SUBJECT', 'DESCRIPTION', 'DESCRIPTION_TYPE', 'SETTINGS'],
            'order' => ['ID' => 'DESC'],
            'start' => 0,
        ]);

        $activityRows = is_array($activities) && isset($activities['items']) ? $activities['items'] : $activities;
        if (!is_array($activityRows)) {
            return null;
        }

        foreach ($activityRows as $activity) {
            $text = trim((string) ($activity['DESCRIPTION'] ?? ''));
            if ($text !== '') {
                return $text;
            }

            $settings = $activity['SETTINGS'] ?? null;
            if (is_array($settings)) {
                foreach (['TRANSCRIPT', 'TRANSCRIPTION', 'TEXT'] as $key) {
                    $candidate = trim((string) ($settings[$key] ?? ''));
                    if ($candidate !== '') {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    private function extractFromDealFields(array $deal): ?string
    {
        $candidateFields = array_merge(
            ['COMMENTS'],
            (array) $this->config->get('transcript_sources.deal_fields', [])
        );

        foreach ($candidateFields as $field) {
            $value = trim((string) ($deal[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
