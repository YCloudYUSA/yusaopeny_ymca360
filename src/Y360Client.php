<?php

namespace Drupal\yusaopeny_ymca360;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelInterface;
use Exception;
use GuzzleHttp\Client;

/**
 * YMCA360 API Client.
 */
class Y360Client {

  /**
   * API endpoint URL.
   *
   * @var string
   */
  protected string $apiUrl;

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected Client $client;

  /**
   * Module configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $config;

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  public LoggerChannelInterface $logger;

  public function __construct(Client $client, ConfigFactoryInterface $configFactory, LoggerChannelInterface $logger) {
    $this->client = $client;
    $this->config = $configFactory->get('yusaopeny_ymca360.settings');
    $this->logger = $logger;
    $this->apiUrl = $this->config->get('api_url') ?: 'https://ymca360.org/api/external/v1/schedules';
  }

  /**
   * Verifies credentials by doing test request to the YMCA360 API.
   *
   * @param array $creds
   *   Credentials [user, password].
   *
   * @return bool|array
   *   Response data on success, FALSE on failure.
   */
  public function verifyCredentials(array $creds) {
    try {
      return $this->doRequest(['size' => 1], ['auth' => array_values($creds)]);
    }
    catch (Exception $e) {
      return FALSE;
    }
  }

  /**
   * Returns schedule filter facets from the YMCA360 API.
   *
   * @return array
   *   Schedule facets, empty array on failure.
   */
  public function getByScheduleFilter(): array {
    try {
      $data = $this->doRequest(['size' => 1]);
      return $data['summary']['facets']['schedule_ids'] ?? [];
    }
    catch (Exception $e) {
      $this->logApiFailure($e);
      return [];
    }
  }

  /**
   * Fetches schedules within a time window using API-native filters.
   *
   * Uses the documented /schedules filters (start_at, end_at, scheduled_from)
   * so the server returns only the slice we care about. See
   * https://github.com/YMCA360/external-api-docs/blob/main/docs/schedules.md
   *
   * Items with status `canceled` and `deleted` are included — the caller is
   * responsible for reconciling them (canceled → unpublish, deleted → remove).
   *
   * @param int $fromTimestamp
   *   Window start (UNIX timestamp, UTC). Maps to API `start_at` filter and
   *   to `scheduled_from` when past items must be included (API hides past
   *   items by default).
   * @param int $toTimestamp
   *   Window end (UNIX timestamp, UTC). Maps to API `end_at` filter.
   * @param int $pageSize
   *   API pagination page size.
   *
   * @return array{items: array, stats: array}
   *   items: flat list of schedule occurrences.
   *   stats: ['pages_fetched' => N, 'api_total' => N, 'window_items' => N].
   */
  public function getSchedulesWindowed(int $fromTimestamp, int $toTimestamp, int $pageSize = 500): array {
    $queryParams = $this->buildWindowedQuery($fromTimestamp, $toTimestamp, $pageSize);

    $items = [];
    $pagesFetched = 0;

    // The YMCA360 API always returns total_pages=0 regardless of the true
    // result set size, so we cannot rely on it. Instead we paginate until
    // we receive a partial page (fewer items than requested), which signals
    // the final page has been reached.
    do {
      $data = $this->doRequest($queryParams);
      $pagesFetched++;

      $pageItems = $data['items'] ?? [];
      if (empty($pageItems)) {
        break;
      }
      $items = array_merge($items, $pageItems);

      $queryParams['page']++;
      usleep(100000);
    } while (count($pageItems) >= $pageSize);

    return [
      'items' => $items,
      'stats' => [
        'pages_fetched' => $pagesFetched,
        'api_total' => count($items),
        'window_items' => count($items),
      ],
    ];
  }

  /**
   * Builds the query params for a windowed schedules fetch.
   */
  protected function buildWindowedQuery(int $fromTimestamp, int $toTimestamp, int $pageSize): array {
    $query = [
      'size' => $pageSize,
      'page' => 0,
      'sort_by' => 'start_at',
      'start_at' => $fromTimestamp,
      'end_at' => $toTimestamp,
      // API hides past items by default (scheduled_from defaults to "now").
      // Override so the full window is returned regardless of current time.
      'scheduled_from' => $fromTimestamp,
    ];
    $scheduleIds = $this->getEnabledScheduleIds();
    if (!empty($scheduleIds)) {
      $query['schedule_id'] = $scheduleIds;
    }
    return $query;
  }

  /**
   * Single-page fetch — used for facets / non-windowed callers.
   *
   * Prefer getSchedulesWindowed() for syncing; this method exists for the
   * locations mapping form (summary facets) and the livestreams extractor.
   */
  public function getSchedules(int $size = 250, array $filters = []): array {
    $queryParams = [
      'size' => $size,
      'page' => 0,
    ] + $filters;
    $scheduleIds = $this->getEnabledScheduleIds();
    if (!empty($scheduleIds)) {
      $queryParams['schedule_id'] = $scheduleIds;
    }
    return $this->doRequest($queryParams);
  }

  /**
   * Returns enabled schedule IDs from config.
   */
  protected function getEnabledScheduleIds(): array {
    $scheduleMapping = $this->config->get('schedule.schedules');
    if (!is_array($scheduleMapping)) {
      return [];
    }
    $ids = [];
    foreach ($scheduleMapping as $scheduleId => $info) {
      if (!empty($info['enable'])) {
        $ids[] = $scheduleId;
      }
    }
    return $ids;
  }

  /**
   * Performs an external HTTP request.
   */
  private function doRequest(array $params, array $options = []): array {
    $options = array_merge([
      'headers' => ['Accept' => 'application/json'],
      'auth' => $this->getAuth(),
      'timeout' => 120,
    ], $options);

    $queryString = $this->buildQueryString($params);
    $options['query'] = $queryString;
    $this->logger->info('Sending request to %uri', [
      '%uri' => $this->apiUrl . '?' . urldecode($queryString),
    ]);

    try {
      $response = $this->client->get($this->apiUrl, $options);
      $content = $response->getBody()->getContents();
      return json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Exception $e) {
      $this->logApiFailure($e);
      throw $e;
    }
  }

  /**
   * Builds query string, stripping numeric indices from array params.
   *
   * Y360 API expects schedule_id[]=X repeated, not schedule_id[0]=X.
   */
  protected function buildQueryString(array $params): string {
    $queryString = http_build_query($params);
    return preg_replace('/%5B[0-9]+%5D/simU', '%5B%5D', $queryString);
  }

  /**
   * Logs an API failure uniformly.
   */
  protected function logApiFailure(\Exception $e): void {
    $this->logger->warning('Unable to get data from YMCA360 API. %code - %msg', [
      '%msg' => $e->getMessage(),
      '%code' => $e->getCode(),
    ]);
  }

  /**
   * Returns [user, password] for basic auth.
   */
  protected function getAuth(): array {
    $credentials = $this->config->get('credentials') ?? [];
    return [$credentials['user'] ?? '', $credentials['password'] ?? ''];
  }

}
