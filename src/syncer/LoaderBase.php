<?php

namespace Drupal\yusaopeny_ymca360\syncer;

use Drupal\Component\Datetime\DateTimePlus;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\yusaopeny_ymca360\Y360MappingRepository;

/**
 * Loads prepared data received from YMCA360 API into storage.
 *
 * @package Drupal\yusaopeny_ymca360.
 */
abstract class LoaderBase implements LoaderInterface {

  /**
   * DataWrapper.
   *
   * @var \Drupal\yusaopeny_ymca360\syncer\DataWrapper
   */
  protected DataWrapper $dataWrapper;

  /**
   * YMCA360 Mapping Repository.
   *
   * @var \Drupal\yusaopeny_ymca360\Y360MappingRepository
   */
  protected Y360MappingRepository $repository;

  /**
   * Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Node Storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $nodeStorage;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;


  /**
   * Drupal State service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Loader class constructor.
   */
  public function __construct(
    DataWrapper $data_wrapper,
    Y360MappingRepository $repository,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelInterface $logger,
    StateInterface $state,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler
  )
  {
    $this->dataWrapper = $data_wrapper;
    $this->repository = $repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->nodeStorage = $this->entityTypeManager->getStorage('node');
    $this->logger = $logger;
    $this->state = $state;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritDoc}
   */
  public function load() {
    $this->createItems();
    $this->updateItems();
    $this->deleteItems();
  }

  /**
   * Processes items to be created.
   *
   * Used by the cron path. The 60-second cap prevents a single cron run from
   * monopolising PHP execution; remaining items are picked up on the next run.
   * Use buildBatch() / drush_backend_batch_process() for a full single-pass run.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function createItems(): void {
    $items = $this->dataWrapper->getItemsToCreate();
    $total = count($items);
    $this->logger->info('[LOADER] There are %total objects to create', ['%total' => $total]);
    $_start = microtime(TRUE);
    $processed = 0;
    foreach ($items as $item) {
      $this->createSession($item);
      $processed++;
      if (microtime(true) - $_start > 60) {
        $this->logger->notice('[LOADER] Time limit reached during create. Processed %done of %total; remainder queued for next run.', [
          '%done' => $processed,
          '%total' => $total,
        ]);
        break;
      }
    }
  }

  /**
   * Processes items to be updated.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function updateItems(): void {
    $items = $this->dataWrapper->getItemsToUpdate();
    $total = count($items);
    $this->logger->info('[LOADER] There are %total objects to update', ['%total' => $total]);
    $_start = microtime(TRUE);
    $processed = 0;
    foreach ($items as $mapping_id => $item) {
      $this->updateSession($mapping_id, $item);
      $processed++;
      if (microtime(true) - $_start > 60) {
        $this->logger->notice('[LOADER] Time limit reached during update. Processed %done of %total; remainder queued for next run.', [
          '%done' => $processed,
          '%total' => $total,
        ]);
        break;
      }
    }
  }

  /**
   * Processes items to be deleted.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function deleteItems(): void {
    $items = $this->dataWrapper->getItemsToDelete();
    $total = count($items);
    $this->logger->info('[LOADER] There are %total objects to delete', ['%total' => $total]);
    $this->runWithoutTrash(function () use ($items, $total) {
      $_start = microtime(TRUE);
      $processed = 0;
      foreach ($items as $item_id) {
        $this->deleteSession($item_id);
        $processed++;
        if (microtime(true) - $_start > 60) {
          $this->logger->notice('[LOADER] Time limit reached during delete. Processed %done of %total; remainder queued for next run.', [
            '%done' => $processed,
            '%total' => $total,
          ]);
          break;
        }
      }
    });
  }

  /**
   * Creates a single session — public entry point for batch callbacks.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function processCreateItem(array $item): void {
    $this->createSession($item);
  }

  /**
   * Updates a single session — public entry point for batch callbacks.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function processUpdateItem(int $mappingId, array $item): void {
    $this->updateSession($mappingId, $item);
  }

  /**
   * Deletes a single session — public entry point for batch callbacks.
   *
   * Wraps runWithoutTrash() so soft-delete is bypassed for reconciliation.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function processDeleteItem(int $mappingId): void {
    $this->runWithoutTrash(function () use ($mappingId) {
      $this->deleteSession($mappingId);
    });
  }

  /**
   * Builds a Drupal Batch API definition for the full load phase.
   *
   * Intended for use by Drush commands that want to process all items in one
   * invocation with visible progress, rather than the 60-second cron slices.
   * After calling this, pass the result to batch_set() and then call
   * drush_backend_batch_process().
   *
   * @param string $loaderServiceId
   *   The Drupal service ID of this loader (e.g.
   *   'yusaopeny_ymca360_instudio.loader'). Passed into each batch operation
   *   so the static callback can retrieve the correct loader from the
   *   container.
   * @param int $chunkSize
   *   Number of items per batch operation. Defaults to 50.
   *
   * @return array
   *   A batch definition suitable for batch_set().
   */
  public function buildBatch(string $loaderServiceId, int $chunkSize = 50): array {
    $batch = [
      'operations' => [],
      'finished' => [static::class, 'batchFinished'],
      'title' => t('Syncing YMCA360 schedules'),
      'init_message' => t('Initialising…'),
      'progress_message' => t('Processing @current of @total operations.'),
      'error_message' => t('YMCA360 sync encountered errors. Check recent log messages.'),
    ];

    foreach (array_chunk($this->dataWrapper->getItemsToCreate(), $chunkSize) as $chunk) {
      $batch['operations'][] = [[static::class, 'batchProcessChunk'], [$loaderServiceId, 'create', $chunk]];
    }
    foreach (array_chunk($this->dataWrapper->getItemsToUpdate(), $chunkSize, TRUE) as $chunk) {
      $batch['operations'][] = [[static::class, 'batchProcessChunk'], [$loaderServiceId, 'update', $chunk]];
    }
    foreach (array_chunk($this->dataWrapper->getItemsToDelete(), $chunkSize) as $chunk) {
      $batch['operations'][] = [[static::class, 'batchProcessChunk'], [$loaderServiceId, 'delete', $chunk]];
    }

    return $batch;
  }

  /**
   * Batch operation callback — processes one chunk of items.
   *
   * Static so it can be serialised into the batch DB table.
   *
   * @param string $serviceId
   *   Loader service ID.
   * @param string $type
   *   'create', 'update', or 'delete'.
   * @param array $chunk
   *   For 'create': flat array of item arrays.
   *   For 'update': associative array keyed by mapping ID.
   *   For 'delete': flat array of mapping IDs.
   * @param array $context
   *   Batch API context.
   */
  public static function batchProcessChunk(string $serviceId, string $type, array $chunk, array &$context): void {
    /** @var static $loader */
    $loader = \Drupal::service($serviceId);
    $processed = 0;

    switch ($type) {
      case 'create':
        foreach ($chunk as $item) {
          $loader->processCreateItem($item);
          $processed++;
        }
        break;

      case 'update':
        foreach ($chunk as $mappingId => $item) {
          $loader->processUpdateItem((int) $mappingId, $item);
          $processed++;
        }
        break;

      case 'delete':
        foreach ($chunk as $mappingId) {
          $loader->processDeleteItem((int) $mappingId);
          $processed++;
        }
        break;
    }

    $context['results'][$type] = ($context['results'][$type] ?? 0) + $processed;
    $context['message'] = sprintf(
      '[LOADER] %s: %d processed so far.',
      ucfirst($type),
      $context['results'][$type]
    );

    // Clear the entity static cache between chunks to prevent memory growth.
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
  }

  /**
   * Batch finished callback — logs totals to watchdog.
   *
   * @param bool $success
   *   Whether all operations completed without error.
   * @param array $results
   *   Accumulated results from each operation.
   * @param array $operations
   *   Any unprocessed operations (non-empty only on failure).
   */
  public static function batchFinished(bool $success, array $results, array $operations): void {
    $logger = \Drupal::logger('yusaopeny_ymca360_syncer');
    if ($success) {
      $logger->notice('[LOADER] Batch complete. Created: %c, Updated: %u, Deleted: %d.', [
        '%c' => $results['create'] ?? 0,
        '%u' => $results['update'] ?? 0,
        '%d' => $results['delete'] ?? 0,
      ]);
    }
    else {
      $logger->error('[LOADER] Batch did not complete. Created: %c, Updated: %u, Deleted: %d. Check logs for errors.', [
        '%c' => $results['create'] ?? 0,
        '%u' => $results['update'] ?? 0,
        '%d' => $results['delete'] ?? 0,
      ]);
    }
  }


   *
   * Sync reconciliation removes occurrences that no longer exist upstream —
   * routing them through soft-delete would leave a growing trash backlog
   * that re-syncs would keep churning through. When trash.manager is
   * present we switch it to the "ignore" context so entity storage performs
   * a real delete.
   */
  protected function runWithoutTrash(callable $callback): void {
    if (!\Drupal::hasService('trash.manager')) {
      $callback();
      return;
    }
    \Drupal::service('trash.manager')->executeInTrashContext('ignore', $callback);
  }

  /**
   * Creates Session Node.
   *
   * @param array $data
   *   Array with data to create the Session Node.
   *
   * @return \Drupal\Node\NodeInterface
   *   Returns created Session node.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function createSession(array $data): NodeInterface {
    $session = $this->entityTypeManager
      ->getStorage('node')
      ->create(['type' => 'session']);

    $location = $this->applySessionFields($session, $data);

    $this->moduleHandler->alter('yusaopeny_ymca360_session', $session, $data);

    $session->save();
    $this->repository->create($data, $session, $location);

    return $session;
  }

  /**
   * Updates Session Node.
   *
   * @param int $mapping_id
   *   Y360Mapping ID.
   * @param array $item
   *   Array with item data to be updated.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function updateSession($mapping_id, array $item): void {
    /** @var \Drupal\yusaopeny_ymca360\Entity\Y360Mapping $mapping */
    $mapping = $this->repository->getStorage()->load($mapping_id);
    /** @var \Drupal\node\NodeInterface $session */
    $session = $mapping->getSession();
    if (!$session) {
      $session = $this->entityTypeManager
        ->getStorage('node')
        ->create(['type' => 'session']);
    }

    $location = $this->applySessionFields($session, $item);

    $this->moduleHandler->alter('yusaopeny_ymca360_session', $session, $item);

    $session->save();

    $this->repository->update($item, $session, $location);
  }

  /**
   * Writes all fields from an API item onto a session node.
   *
   * Resolved location is returned so callers can reuse it for the mapping.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   Location node or NULL if unresolved.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function applySessionFields(NodeInterface $session, array $item): ?EntityInterface {
    $session->setTitle($this->getSessionTitle($item));
    $session->set('field_session_class', $this->getClass($item));
    $session->set('field_session_time', $this->getSessionTime($item));
    $session->set('field_session_room', $item['studio_name'] ?? NULL);
    $session->set('field_session_instructor', $item['instructor_name'] ?? NULL);
    $session->set('field_session_description', $item['description'] ?? NULL);
    $session->set('field_session_min_age', $item['min_age'] ?? NULL);
    $session->set('field_session_max_age', $item['max_age'] ?? NULL);

    $this->applyOptionalFields($session, $item);

    $location = $this->getLocation($item['branch_id'], $item['kind']);
    if ($location) {
      $session->set('field_session_location', ['target_id' => $location->id() ?? 0]);
    }

    $this->applyPublishState($session, $item);

    return $location;
  }

  /**
   * Writes optional fields only when the bundle exposes them.
   *
   * Lets themes opt in to extra data (substitute instructor, upstream
   * status) without forcing sites to adopt new fields.
   */
  protected function applyOptionalFields(NodeInterface $session, array $item): void {
    if ($session->hasField('field_wait_list_availability')) {
      $session->set('field_wait_list_availability', $item['wait_list_availability']);
    }
    if ($session->hasField('field_session_original_instructor')) {
      $session->set('field_session_original_instructor', $item['original_instructor_name'] ?? NULL);
    }
    if ($session->hasField('field_session_status')) {
      $session->set('field_session_status', $item['status'] ?? NULL);
    }
  }

  /**
   * Applies publish state to a session according to upstream data + config.
   *
   * When Content Moderation governs the session bundle, setPublished() alone
   * is not enough — the moderation_state field controls the final revision
   * status and must be set explicitly, otherwise nodes are always saved as
   * 'draft' regardless of the published flag.
   */
  protected function applyPublishState(NodeInterface $session, array $item): void {
    $publish = $this->isPublishedSession($item);
    if ($publish) {
      $session->setPublished();
    }
    else {
      $session->setUnpublished();
    }
    if ($session->hasField('moderation_state')) {
      $session->set('moderation_state', $publish ? 'published' : 'draft');
    }
  }

  /**
   * Builds session title.
   *
   * @param array $item
   *   Session item data.
   *
   * @return mixed|string
   *   Session title.
   */
  protected function getSessionTitle(array $item) {
    $title = $item['title'];
    if (($item['status'] ?? '') !== 'canceled') {
      return $title;
    }
    $prefix = $this->getLoaderConfig()->get('sync.canceled_title_prefix');
    if ($prefix === NULL) {
      $prefix = 'CANCELED: ';
    }
    return $prefix . $title;
  }

  /**
   * Checks if the session should be published.
   *
   * @param array $item
   *   Session item data.
   *
   * @return bool
   *   True if session is supposed to be published.
   */
  protected function isPublishedSession(array $item): bool {
    $status = $item['status'] ?? '';
    if ($status === 'deleted') {
      return FALSE;
    }
    if ($status === 'canceled') {
      return $this->shouldPublishCanceled($item);
    }
    return !empty($item['published']) && in_array($status, ['scheduled', 'moved'], TRUE);
  }

  /**
   * Applies canceled-specific publish policy.
   */
  protected function shouldPublishCanceled(array $item): bool {
    $behavior = $this->getLoaderConfig()->get('sync.canceled_publish_behavior') ?: 'follow_api';
    return match ($behavior) {
      'always_unpublish' => FALSE,
      'keep_published' => TRUE,
      default => !empty($item['published']),
    };
  }

  /**
   * Returns the submodule settings config for Loader-level UX decisions.
   *
   * Submodules override getSubmoduleConfigName() to point at their own
   * settings key; the default falls back to the shared settings.
   */
  protected function getLoaderConfig() {
    return $this->configFactory->get($this->getSubmoduleConfigName());
  }

  /**
   * Returns the submodule settings config name. Override in subclasses.
   */
  protected function getSubmoduleConfigName(): string {
    return 'yusaopeny_ymca360.settings';
  }

  /**
   * Deletes Session Node.
   *
   * @param int $mapping_id
   *   Y360Mapping ID.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function deleteSession(int $mapping_id): void {
    /** @var \Drupal\yusaopeny_ymca360\Entity\Y360Mapping|null $mapping */
    $mapping = $this->repository->getStorage()->load($mapping_id);
    if (!$mapping) {
      $this->logger->info('[LOADER] Skip delete: mapping %id is gone (likely cascaded by node delete).', ['%id' => $mapping_id]);
      return;
    }
    $session = $mapping->getSession();
    if ($session) {
      $session->delete();
    }
    else {
      $this->logger->info('[LOADER] Mapping %id has no linked session; removing mapping only.', ['%id' => $mapping_id]);
    }
    $this->repository->delete($mapping_id);
  }

  /**
   * Gets Location ID from mapping settings.
   *
   * @param int|null $branch_id
   *   YMCA360 Branch ID.
   * @param string|null $kind
   *   YMCA360 event type.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   Location or NULL.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getLocation(?int $branch_id, ?string $kind): ?EntityInterface {
    static $map = [];

    if ($kind === 'Live Stream') {
      return $this->getLivestreamLocation();
    }

    if (empty($map)) {
      $locations_mapping = $this->configFactory->get('yusaopeny_ymca360.locations_mapping')->get('locations') ?? [];
      array_map(function ($item) use (&$map) {
        $pieces = explode(',', $item);
        $location = $this->nodeStorage->loadByProperties(['title' => $pieces[1]]);
        if (is_array($location) && !empty($location)) {
          $location = reset($location);
          // Convert location Title into location ID.
          $map[$pieces[0]] = $location;
        }
      }, $locations_mapping);
    }
    return $map[$branch_id] ?? NULL;
  }

  /**
   * Returns the Livestream location.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The virtual location entity if set.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getLivestreamLocation(): ?EntityInterface {
    if (!$id = $this->configFactory->get('yusaopeny_ymca360.locations_mapping')->get('virtual_location')) {
      return NULL;
    }
    return $this->nodeStorage->load($id);
  }


  /**
   * Creates Session Time paragraph.
   *
   * @param array $data
   *   Array with source data.
   *
   * @return array
   *   Array with paragraphs references.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function getSessionTime(array $data) {
    $timezone = $this->configFactory->get('system.date')->get('timezone')['default'];
    $day = (new DateTimePlus($data['start_at']))->setTimezone(new \DateTimeZone($timezone))->format('l');

    $paragraphs = [];
    $paragraph = $this->entityTypeManager
      ->getStorage('paragraph')
      ->create(['type' => 'session_time']);
    $paragraph->set('field_session_time_days', [strtolower($day)]);
    $paragraph->set('field_session_time_date', [
      'value' => $this->repository->formatIsoDate($data['start_at']),
      'end_value' => $this->repository->formatIsoDate($data['end_at']),
    ]);
    $paragraph->save();

    $paragraphs[] = [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];

    return $paragraphs;
  }

  /**
   * Creates class or use existing.
   *
   * @param array $data
   *   Class data.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   Class node.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function getClass(array $data) {
    $activity_id = $this->getActivity((string) ($data['category_name'] ?? 'Uncategorized'), (int) $data['schedule_id']);
    // Try to find class.
    $existing_classes = $this->nodeStorage
      ->getQuery()
      ->condition('type', 'class')
      ->condition('title', $data['title'])
      ->condition('field_class_activity', $activity_id)
      ->accessCheck(FALSE)
      ->execute();

    if (!empty($existing_classes)) {
      $class_id = reset($existing_classes);
      /** @var \Drupal\node\Entity\Node $class*/
      $class = $this->nodeStorage->load($class_id);
      $class->set('field_class_description', $data['description']);
      $class->save();
    }
    else {
      $class = $this->nodeStorage
        ->create([
          'type' => 'class',
          'title' => $data['title'],
          'moderation_state' => 'published',
          'field_class_activity' => [['target_id' => $activity_id]],
        ]);
      $class->set('field_class_description', $data['description']);
      $class->setPublished();

      $this->moduleHandler->alter('yusaopeny_ymca360_class_create', $class, $data);

      $class->save();
    }

    return $class;
  }

  /**
   * Gets or creates Activity node.
   *
   * @param string $activity_name
   *   The activity name.
   * @param int $schedule_id
   *   The source schedule ID.
   *
   * @return int
   *   Activity node ID.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function getActivity(string $activity_name, int $schedule_id): int {
    // Try to get existing activity.
    $existingActivities = $this->nodeStorage
      ->getQuery()
      ->condition('title', $activity_name)
      ->condition('type', 'activity')
      ->condition('field_activity_category', $this->getActivityCategory($schedule_id))
      ->accessCheck(FALSE)
      ->execute();

    if ($existingActivities) {
      return reset($existingActivities);
    }

    // No activities found. Create one.
    $activity = $this->nodeStorage->create([
      'type' => 'activity',
      'title' => $activity_name,
      'moderation_state' => 'published',
      'field_activity_category' => [['target_id' => $this->getActivityCategory($schedule_id)]],
    ]);
    $activity->setPublished();

    $this->moduleHandler->alter('yusaopeny_ymca360_activity_create', $activity);

    $activity->save();
    return $activity->id();
  }

  /**
   * Returns Activity Category Node id stored into state variable.
   *
   * @param int $schedule_id
   *   The source schedule ID.
   *
   * @return int
   */
  protected function getActivityCategory(int $schedule_id): int {
    $config = $this->configFactory->get('yusaopeny_ymca360.settings')->get('schedule.schedules');
    return (int) $config[$schedule_id]['subcategory'] ?? 0;
  }

}
