<?php

namespace Drupal\migrate_embargoes_to_embargo\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Plugin\MigrationInterface;

use Drupal\Core\Entity\EntityTypeManagerInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Source a migration by selecting entities by type.
 *
 * @MigrateSource(
 *   id = "migrate_embargoes_to_embargo.source.entity"
 * )
 */
class Entity extends SourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  protected string $entityTypeId;

  /**
   * Constructor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrationInterface $migration,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);

    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeId = $this->configuration['entity_type']
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrationInterface $migration = NULL
  ) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('entity_type.manager')
    );
  }

  protected function getType() : EntityTypeInterface {
    return $this->entityTypeManager->getDefinition($this->entityTypeId);
  }
  protected function getStorage() : StorageInterface {
    return $this->entityTypeManager->getStorage($this->entityTypeId);
  }

  protected function mapProp(TypedDataInterface $property) {
    $def = $property->getDataDefinition();
    return $this->t('@label: @description', [
      '@label' => $def->getLabel(),
      '@description' => $def->getDescription(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $properties = $this->getType()->getTypedData()->getProperties();
    return array_map([$this, 'mapProp'], $properties);
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return $this->configuration['keys'];
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    // XXX: Loading all entities like this would fall apart if there was a
    // particularly large number of them; however, we do not expect this to be
    // used with terribly many at the moment... like only 10s or 100s.
    $entities = $this->getStorage()->loadMultiple();
    foreach ( as $id => $entity) {
      yield $id => $entity->toArray();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rewind() {
    unset($this->iterator);
    $this->next();
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return $this->t('"@type" entity source', ['@type' => $this->entityTypeId]);
  }

}
