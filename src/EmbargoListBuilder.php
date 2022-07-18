<?php

namespace Drupal\embargo;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a listing of Embargo entities.
 */
class EmbargoListBuilder extends EntityListBuilder {

  /**
   * The embargo content entity field definitions.
   *
   * @var \Drupal\Core\Field\FieldDefinitionInterface[]
   */
  protected $fields;

  /**
   * Constructs a new EntityListBuilder object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage class.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $field_manager
   *   An entity field manager.
   */
  public function __construct(EntityTypeInterface $entity_type, EntityStorageInterface $storage, EntityFieldManagerInterface $field_manager) {
    parent::__construct($entity_type, $storage);
    $this->fields = $field_manager->getFieldDefinitions($entity_type->id(), NULL);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header = [];
    foreach ($this->fields as $field => $definition) {
      if (!is_null($definition->getDisplayOptions('view'))) {
        $header[$field] = $definition->getLabel();
      }
    }
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $columns = [];
    foreach ($this->fields as $field => $definition) {
      if (!is_null($definition->getDisplayOptions('view'))) {
        $columns[$field]['data'] = $entity->{$field}->view('default');
      }
    }
    return $columns + parent::buildRow($entity);
  }

}
