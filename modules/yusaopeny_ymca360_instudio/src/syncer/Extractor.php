<?php

namespace Drupal\yusaopeny_ymca360_instudio\syncer;

use Drupal\yusaopeny_ymca360\syncer\ExtractorBase;
use Drupal\yusaopeny_ymca360\syncer\ExtractorInterface;

/**
 * Fetches in-studio session data from the YMCA360 API.
 *
 * Uses the API's native start_at / end_at / scheduled_from filters to pull
 * only the items that fall inside the sync window.
 */
class Extractor extends ExtractorBase implements ExtractorInterface {

  /**
   * {@inheritdoc}
   */
  public function extract() {
    $instudio = $this->configFactory->get('yusaopeny_ymca360_instudio.settings');
    $window = $this->resolveSyncWindow($instudio);
    $pageSize = (int) ($instudio->get('sync.page_size') ?? 500);

    $this->logger->notice('[EXTRACTOR] Fetching YMCA360 schedules. Window %from → %to.', [
      '%from' => gmdate(\DateTimeInterface::ATOM, $window['from']),
      '%to' => gmdate(\DateTimeInterface::ATOM, $window['to']),
    ]);

    $result = $this->client->getSchedulesWindowed($window['from'], $window['to'], $pageSize);
    $items = $result['items'] ?? [];
    $stats = $result['stats'] ?? [];

    $this->logger->info('[EXTRACTOR] Window fetch: %pages pages, %total items collected.', [
      '%pages' => $stats['pages_fetched'] ?? 0,
      '%total' => $stats['api_total'] ?? 0,
    ]);

    $this->dataWrapper->setItems($items);
    $this->dataWrapper->setSyncWindow($window['from'], $window['to']);
    $this->dataWrapper->setMaxDeletesPerRun((int) ($instudio->get('sync.max_deletes_per_run') ?? 500));
    $this->applyEmptyExtractCircuitBreaker(count($items), (int) ($instudio->get('sync.empty_extract_threshold') ?? 2));
  }

  /**
   * Circuit breaker for the orphan-reconciliation step.
   *
   * Tracks how many sync runs in a row produced an empty extract. When the
   * count reaches the threshold (default 2), instructs the transformer to
   * skip orphan-by-absence deletion this run — the API state is unreliable
   * and otherwise every stored mapping that did not come back would be
   * flagged for deletion. Resets to zero as soon as the API returns at
   * least one item.
   */
  protected function applyEmptyExtractCircuitBreaker(int $itemCount, int $threshold): void {
    $store = $this->keyValueFactory->get('yusaopeny_ymca360_syncer');
    $key = 'instudio.empty_extract_streak';
    if ($itemCount > 0) {
      $store->set($key, 0);
      $this->dataWrapper->setSkipOrphanReconciliation(FALSE);
      return;
    }
    $streak = (int) $store->get($key, 0) + 1;
    $store->set($key, $streak);
    $vars = ['%s' => $streak, '%t' => $threshold];
    if ($streak >= $threshold) {
      $this->logger->warning('[EXTRACTOR] %s consecutive empty extracts (>= %t) — emptiness confirmed, running orphan reconciliation.', $vars);
      $this->dataWrapper->setSkipOrphanReconciliation(FALSE);
      return;
    }
    $this->logger->notice('[EXTRACTOR] Empty extract %s of %t — skipping orphan reconciliation until emptiness is confirmed.', $vars);
    $this->dataWrapper->setSkipOrphanReconciliation(TRUE);
  }

  /**
   * Resolves the current sync window from config.
   *
   * The window starts at midnight of `window_offset_days` days ago so the
   * full previous day is always covered.
   *
   * @return array{from: int, to: int}
   */
  protected function resolveSyncWindow($instudio): array {
    $windowDays = (int) ($instudio->get('sync.window_days') ?? 14);
    $offsetDays = (int) ($instudio->get('sync.window_offset_days') ?? 1);
    $now = time();
    $from = (new \DateTime("midnight -{$offsetDays} days", new \DateTimeZone(date_default_timezone_get())))->getTimestamp();
    return [
      'from' => $from,
      'to' => $now + ($windowDays * 86400),
    ];
  }

}
