<?php

/**
 * @file
 * YUSA OpenY YMCA360 integration content migrations.
 */

/**
 * Resets hashes to force data sync.
 */
function yusaopeny_ymca360_post_update_reset_hashes_001() {
  \Drupal::service('yusaopeny_ymca360.mapping_repository')->resetHashes();
}

/**
 * Widens y360id from integer to string; some YMCA360 ids exceed INT range.
 *
 * y360id is registered as a custom entity key (see the entity_keys
 * annotation on Y360Mapping), so Drupal's normal
 * uninstallFieldStorageDefinition()/installFieldStorageDefinition() path for
 * changing a field's type does not apply cleanly here: uninstalling wipes
 * the column's data into a "deleted field" purge table instead of doing a
 * clean drop, corrupting the base table. A plain ALTER TABLE ... CHANGE
 * avoids that entirely — MySQL casts the existing integers to their string
 * form in place, so no data migration step is needed — and Drupal's cached
 * field metadata is then synced directly to match.
 */
function yusaopeny_ymca360_post_update_widen_y360id_002() {
  $connection = \Drupal::database();

  // Not required for the migration itself (the ALTER below is
  // non-destructive), kept as an audit trail given prior/partial runs of
  // this update corrupted the column.
  $backup_file = \Drupal::service('file_system')->getTempDirectory() . '/y360_mapping_y360id_backup.json';
  $backup = $connection->select('y360_mapping', 'm')
    ->fields('m', ['id', 'y360id'])
    ->execute()
    ->fetchAllKeyed();
  file_put_contents($backup_file, json_encode($backup));

  $connection->schema()->changeField('y360_mapping', 'y360id', 'y360id', [
    'type' => 'varchar',
    'length' => 64,
    'not null' => FALSE,
    'default' => '',
  ]);

  $new_definition = \Drupal::service('entity_field.manager')
    ->getBaseFieldDefinitions('y360_mapping')['y360id'];
  $defs_kv = \Drupal::keyValue('entity.definitions.installed');
  $defs = $defs_kv->get('y360_mapping.field_storage_definitions');
  $defs['y360id'] = $new_definition;
  $defs_kv->set('y360_mapping.field_storage_definitions', $defs);

  \Drupal::keyValue('entity.storage_schema.sql')->set('y360_mapping.field_schema_data.y360id', [
    'y360_mapping' => [
      'fields' => [
        'y360id' => [
          'type' => 'varchar',
          'length' => 64,
          'binary' => FALSE,
          'not null' => FALSE,
        ],
      ],
    ],
  ]);
}
