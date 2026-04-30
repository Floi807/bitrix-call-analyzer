<?php

declare(strict_types=1);

namespace App\Service;

use App\Analyzer\TranscriptAnalyzer;
use App\Bitrix\PortalClient;
use App\Bitrix\StateRepository;
use App\Support\Logger;

final class DealProcessor
{
    public function __construct(
        private readonly TranscriptProvider $transcripts,
        private readonly CatalogProvider $catalog,
        private readonly TranscriptAnalyzer $analyzer,
        private readonly DealProductUpdater $updater,
        private readonly StateRepository $state,
        private readonly Logger $logger
    ) {
    }

    public function process(PortalClient $client, int $dealId, bool $force = false): array
    {
        $transcript = $this->transcripts->getForDeal($client, $dealId);
        if ($transcript === null || trim($transcript) === '') {
            return ['status' => 'skipped', 'reason' => 'Transcript not found.'];
        }

        $hash = sha1($transcript);
        $memberId = $client->memberId();
        $previousHash = $this->state->getDealHash($memberId, $dealId);

        if (!$force && $previousHash === $hash) {
            return ['status' => 'skipped', 'reason' => 'Transcript already processed.'];
        }

        $catalog = $this->catalog->getProducts($client);
        $rows = $this->analyzer->analyze($transcript, $catalog);

        if ($rows === []) {
            $this->state->putDealState($memberId, $dealId, [
                'transcript_hash' => $hash,
                'last_status' => 'no_match',
                'updated_at' => date('c'),
            ]);

            return ['status' => 'skipped', 'reason' => 'Products were not recognized.'];
        }

        $this->updater->replaceRows($client, $dealId, $rows);
        $this->state->putDealState($memberId, $dealId, [
            'transcript_hash' => $hash,
            'last_status' => 'updated',
            'updated_at' => date('c'),
            'rows' => $rows,
        ]);

        $this->logger->info('Deal products updated from transcript.', [
            'member_id' => $memberId,
            'deal_id' => $dealId,
            'rows_count' => count($rows),
        ]);

        return ['status' => 'updated', 'deal_id' => $dealId, 'rows' => $rows];
    }

    public function preview(PortalClient $client, int $dealId): array
    {
        $transcript = $this->transcripts->getForDeal($client, $dealId);
        if ($transcript === null || trim($transcript) === '') {
            return [
                'deal_id' => $dealId,
                'transcript_found' => false,
                'transcript' => null,
                'rows' => [],
            ];
        }

        $catalog = $this->catalog->getProducts($client);

        return [
            'deal_id' => $dealId,
            'transcript_found' => true,
            'transcript' => $transcript,
            'catalog_size' => count($catalog),
            'rows' => $this->analyzer->analyze($transcript, $catalog),
        ];
    }
}
