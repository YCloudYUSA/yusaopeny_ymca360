<?php

namespace Drupal\yusaopeny_ymca360_instudio\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure YMCA360 Integration settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'yusaopeny_ymca360_instudio_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['yusaopeny_ymca360_instudio.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('yusaopeny_ymca360_instudio.settings');

    $form['cron'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Automatic Sync'),
      '#tree' => TRUE,
    ];
    $form['cron']['enable_cron'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable sync on cron runs'),
      '#default_value' => $config->get('cron.enable_cron'),
    ];

    $form['sync'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Sync Window'),
      '#description' => $this->t('Only sessions whose start time falls inside this window are pulled from the YMCA360 API and reconciled in Drupal. Sessions outside the window are left untouched by regular syncs.'),
      '#tree' => TRUE,
    ];
    $form['sync']['window_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Future days (window size)'),
      '#description' => $this->t('How many days ahead of today to include in the sync window.'),
      '#default_value' => $config->get('sync.window_days') ?? 14,
      '#min' => 1,
      '#max' => 365,
      '#step' => 1,
    ];
    $form['sync']['window_offset_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Past days (window offset)'),
      '#description' => $this->t('How many days <em>before</em> today to include in the sync window. Keeps occurrences that already started but have not yet ended (e.g. an Open Swim 5am–5pm shown at 11am) from being reconciled away. Set to 0 to drop any occurrence the moment it starts. Increase if you run multi-day occurrences.'),
      '#default_value' => $config->get('sync.window_offset_days') ?? 1,
      '#min' => 0,
      '#max' => 30,
      '#step' => 1,
    ];
    $form['sync']['page_size'] = [
      '#type' => 'number',
      '#title' => $this->t('API page size'),
      '#description' => $this->t('Number of items fetched per API page. Larger pages = fewer requests but more memory per request.'),
      '#default_value' => $config->get('sync.page_size') ?? 500,
      '#min' => 50,
      '#max' => 1000,
      '#step' => 50,
    ];
    $form['sync']['max_import_per_run'] = [
      '#type' => 'number',
      '#title' => $this->t('Max amount to import per run'),
      '#description' => $this->t('There is a hard limit on the amount of data imported per synchronization cycle.'),
      '#default_value' => $config->get('sync.max_import_per_run') ?? 10000,
      '#min' => 0,
      '#max' => 20000,
      '#step' => 10,
    ];
    $form['sync']['max_deletes_per_run'] = [
      '#type' => 'number',
      '#title' => $this->t('Max deletes per run'),
      '#description' => $this->t('Hard cap on deletions per sync cycle. If the reconciliation delete list exceeds this, the sync logs a warning and trims to the cap, deferring the rest to subsequent runs. Set to 0 to disable the cap.'),
      '#default_value' => $config->get('sync.max_deletes_per_run') ?? 500,
      '#min' => 0,
      '#max' => 100000,
      '#step' => 10,
    ];

    $form['canceled'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Canceled sessions'),
      '#description' => $this->t('How to represent occurrences whose upstream status is <code>canceled</code>. Deleted occurrences are always removed.'),
      '#tree' => TRUE,
    ];
    $form['canceled']['title_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title prefix'),
      '#description' => $this->t('Prepended to the session title when the occurrence is canceled. Leave empty to keep the original title.'),
      '#default_value' => $config->get('sync.canceled_title_prefix') ?? 'CANCELED: ',
      '#size' => 40,
    ];
    $form['canceled']['publish_behavior'] = [
      '#type' => 'radios',
      '#title' => $this->t('Publish behavior'),
      '#options' => [
        'follow_api' => $this->t('Follow API <code>published</code> flag (canceled → typically unpublished)'),
        'always_unpublish' => $this->t('Always unpublish canceled sessions'),
        'keep_published' => $this->t('Keep canceled sessions published (show with prefix)'),
      ],
      '#default_value' => $config->get('sync.canceled_publish_behavior') ?? 'follow_api',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $cancelValues = $form_state->getValue('canceled') ?? [];
    $syncValues = $form_state->getValue('sync') ?? [];
    $syncValues['canceled_title_prefix'] = $cancelValues['title_prefix'] ?? 'CANCELED: ';
    $syncValues['canceled_publish_behavior'] = $cancelValues['publish_behavior'] ?? 'follow_api';

    $this->config('yusaopeny_ymca360_instudio.settings')
      ->set('cron', $form_state->getValue('cron'))
      ->set('sync', $syncValues)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
