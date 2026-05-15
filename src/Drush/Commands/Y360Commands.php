<?php

namespace Drupal\yusaopeny_ymca360\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\ymca_sync\SyncerRunner;
use Drupal\yusaopeny_ymca360\Y360Client;
use Drupal\yusaopeny_ymca360\Y360MappingRepository;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for the YMCA360 syncer.
 */
final class Y360Commands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'yusaopeny_ymca360.mapping_repository')]
    private readonly Y360MappingRepository $repository,
    #[Autowire(service: 'yusaopeny_ymca360.y360_client')]
    private readonly Y360Client $client,
    #[Autowire(service: 'ymca_sync.syncer')]
    private readonly SyncerRunner $syncerRunner,
    #[Autowire(service: 'database')]
    private readonly Connection $database,
    #[Autowire(service: 'date.formatter')]
    private readonly DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct();
  }

  /**
   * Resets all stored Y360 mapping hashes so the next sync re-applies fields.
   *
   * Use this after changing settings that affect how a session is rendered
   * (canceled_publish_behavior, canceled_title_prefix, optional fields).
   * Without a reset the next sync hits the no-op path for unchanged API
   * items and existing sessions keep the old field values.
   */
  #[CLI\Command(name: 'y360:reset-hashes', aliases: ['y360-rh'])]
  #[CLI\Usage(name: 'drush y360:reset-hashes', description: 'Force every mapping to update on the next sync run.')]
  public function resetHashes(): void {
    $this->repository->resetHashes();
    $this->logger()->success(dt('YMCA360 mapping hashes reset. Run "drush y360:sync" or wait for cron.'));
  }

  /**
   * Runs the in-studio syncer once.
   *
   * Convenience wrapper over `drush yn-sync yusaopeny_ymca360_instudio.syncer`.
   */
  #[CLI\Command(name: 'y360:sync', aliases: ['y360-sync'])]
  #[CLI\Option(name: 'syncer', description: 'Syncer service id to run.')]
  #[CLI\Usage(name: 'drush y360:sync', description: 'Run the in-studio syncer.')]
  public function sync(array $options = ['syncer' => 'yusaopeny_ymca360_instudio.syncer']): void {
    $syncerId = $options['syncer'];
    $this->logger()->notice(dt('Running @syncer.', ['@syncer' => $syncerId]));
    $this->syncerRunner->run($syncerId, 'proceed');
    $this->logger()->success(dt('Sync run finished. Check `drush ws --type=yusaopeny_ymca360_syncer` for details.'));
  }

  /**
   * Prints current syncer status: counts, window distribution, last sync.
   */
  #[CLI\Command(name: 'y360:status', aliases: ['y360-st'])]
  #[CLI\FieldLabels(labels: [
    'metric' => 'Metric',
    'value' => 'Value',
  ])]
  #[CLI\DefaultTableFields(fields: ['metric', 'value'])]
  public function status(): \Consolidation\OutputFormatters\StructuredData\RowsOfFields {
    $now = \Drupal::time()->getRequestTime();
    $instudio = \Drupal::config('yusaopeny_ymca360_instudio.settings');
    $windowDays = (int) ($instudio->get('sync.window_days') ?? 14);
    $offsetDays = (int) ($instudio->get('sync.window_offset_days') ?? 1);
    $from = gmdate(DateTimeItemInterface::DATETIME_STORAGE_FORMAT, $now - $offsetDays * 86400);
    $to = gmdate(DateTimeItemInterface::DATETIME_STORAGE_FORMAT, $now + $windowDays * 86400);

    $total = (int) $this->database->query('SELECT COUNT(*) FROM {y360_mapping}')->fetchField();
    $inWindow = (int) $this->database->query('SELECT COUNT(*) FROM {y360_mapping} WHERE start_at BETWEEN :f AND :t', [':f' => $from, ':t' => $to])->fetchField();
    $past = (int) $this->database->query('SELECT COUNT(*) FROM {y360_mapping} WHERE start_at < :f', [':f' => $from])->fetchField();
    $future = (int) $this->database->query('SELECT COUNT(*) FROM {y360_mapping} WHERE start_at > :t', [':t' => $to])->fetchField();
    $emptyHash = (int) $this->database->query("SELECT COUNT(*) FROM {y360_mapping} WHERE hash = '' OR hash IS NULL")->fetchField();
    $cronEnabled = (bool) $instudio->get('cron.enable_cron');

    $rows = [
      ['metric' => 'Window', 'value' => sprintf('%s → %s (%dd)', $from, $to, $windowDays)],
      ['metric' => 'Mappings: total', 'value' => (string) $total],
      ['metric' => 'Mappings: in window', 'value' => (string) $inWindow],
      ['metric' => 'Mappings: past (orphaned)', 'value' => (string) $past],
      ['metric' => 'Mappings: future (beyond window)', 'value' => (string) $future],
      ['metric' => 'Mappings: with empty hash (will update)', 'value' => (string) $emptyHash],
      ['metric' => 'Cron enable_cron', 'value' => $cronEnabled ? 'yes' : 'no'],
      ['metric' => 'Canceled publish behavior', 'value' => (string) $instudio->get('sync.canceled_publish_behavior')],
      ['metric' => 'Canceled title prefix', 'value' => (string) $instudio->get('sync.canceled_title_prefix')],
      ['metric' => 'Max deletes per run', 'value' => (string) $instudio->get('sync.max_deletes_per_run')],
    ];

    $cronLast = \Drupal::state()->get('system.cron_last');
    if ($cronLast) {
      $rows[] = [
        'metric' => 'Drupal cron last run',
        'value' => $this->dateFormatter->format($cronLast, 'short') . ' (' . $this->dateFormatter->formatInterval($now - $cronLast) . ' ago)',
      ];
    }

    return new \Consolidation\OutputFormatters\StructuredData\RowsOfFields($rows);
  }

  /**
   * Pings the YMCA360 API and prints a one-line OK / failure message.
   */
  #[CLI\Command(name: 'y360:ping', aliases: ['y360-ping'])]
  public function ping(): void {
    $facets = $this->client->getByScheduleFilter();
    if (empty($facets)) {
      throw new \RuntimeException('YMCA360 API returned no facets. Check credentials and watchdog (channel: yusaopeny_ymca360_syncer).');
    }
    $this->logger()->success(dt('YMCA360 API reachable. @count schedule_id facets exposed.', ['@count' => count($facets)]));
  }

}
