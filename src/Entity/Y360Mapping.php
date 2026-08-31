<?php

namespace Drupal\yusaopeny_ymca360\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the YMCA360 mapping entity class.
 *
 * @ContentEntityType(
 *   id = "y360_mapping",
 *   label = @Translation("YMCA360 Mapping"),
 *   label_collection = @Translation("YMCA360 Mappings"),
 *   handlers = {
 *     "form" = {
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *     "list_builder" = "Drupal\yusaopeny_ymca360\MappingListBuilder",
 *   },
 *   base_table = "y360_mapping",
 *   admin_permission = "access yusaopeny_ymca360 mapping overview",
 *   entity_keys = {
 *     "id" = "id",
 *     "y360id" = "y360id",
 *     "session" = "session",
 *     "locationid" = "locationid",
 *     "uuid" = "uuid"
 *   },
 *   links = {
 *     "add-form" = "/admin/content/y360-mapping/add",
 *     "canonical" = "/y360-mapping/{y360_mapping}",
 *     "edit-form" = "/admin/content/y360-mapping/{y360_mapping}/edit",
 *     "delete-form" = "/admin/content/y360-mapping/{y360_mapping}/delete",
 *     "collection" = "/admin/content/y360-mapping"
 *   },
 * )
 */
class Y360Mapping extends ContentEntityBase {

  /**
   * Gets Y360ID field value.
   *
   * @return int
   *   Y360ID.
   */
  public function getY360Id() {
    return $this->get('y360id')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getHash() {
    return $this->get('hash')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getLocation() {
    return $this->get('location')->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function getSession() {
    return $this->get('session')->entity;
  }

  /**
   * {@inheritDoc}
   */
  public function getSourceData() {
    return $this->get('source_data')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['y360id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('YMCA360 ID'))
      ->setDescription(t('Used to map source YMCA360 ID.'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 64,
        'default_value' => '',
      ]);

    $fields['session'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Session'))
      ->setDefaultValue(0)
      ->setDescription(t('Reference to the Session.'))
      ->setSetting('target_type', 'node');

    $fields['location'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Location'))
      ->setDefaultValue(0)
      ->setDescription(t('Reference to the Location.'))
      ->setSetting('target_type', 'node');

    $fields['start_at'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('Start at'))
      ->setDescription(t('Stores Session start DateTime from YMCA360'));

    $fields['hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Hash'))
      ->setDescription(t('Used to map source data hash.'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setSettings([
        'default_value' => '',
        'max_length' => 255,
      ]);

    $fields['source_data'] = BaseFieldDefinition::create('map')
      ->setLabel(t('YMCA360 raw data'));

    return $fields;
  }

}
