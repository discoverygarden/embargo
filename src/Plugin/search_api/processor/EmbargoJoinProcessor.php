<?php

namespace Drupal\embargo\Plugin\search_api\processor;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\islandora_hierarchical_access\LUTGeneratorInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A search_api processor to add embargo related info.
 *
 * @SearchApiProcessor(
 *   id = "embargo_join_processor",
 *   label = @Translation("Embargo access, join-wise"),
 *   description = @Translation("Add information regarding embargo access constraints."),
 *   stages = {
 *     "add_properties" = 20,
 *     "pre_index_save" = 20,
 *     "preprocess_query" = 20,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class EmbargoJoinProcessor extends ProcessorPluginBase implements ContainerFactoryPluginInterface {

  const ENTITY_TYPES = ['file', 'media', 'node'];

  /**
   * The currently logged-in user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Drupal's database connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);

    $instance->currentUser = $container->get('current_user');
    $instance->database = $container->get('database');

    return $instance;
  }

  /**
   * {@inheritDoc}
   */
  public static function supportsIndex(IndexInterface $index) {
    return parent::supportsIndex($index) && in_array('entity:embargo', $index->getDatasourceIds());
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) : array {
    $properties = [];

    if ($datasource === NULL) {
      $properties['embargo_node'] = new ProcessorProperty([
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
        'is_computed' => TRUE,
      ]);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   *
   * Adapted from search_api's reverse_entity_references processor.
   *
   * @see \Drupal\search_api\Plugin\search_api\processor\ReverseEntityReferences::addFieldValues()
   */
  public function addFieldValues(ItemInterface $item) : void {
    if (!in_array($item->getDatasource()->getEntityTypeId(), static::ENTITY_TYPES)) {
      return;
    }
    try {
      $entity = $item->getOriginalObject()->getValue();
    }
    catch (SearchApiException) {
      return;
    }
    if (!($entity instanceof EntityInterface)) {
      return;
    }

    $embargo_node_fields = $this->getFieldsHelper()->filterForPropertyPath($item->getFields(FALSE), NULL, 'embargo_node');
    if ($embargo_node_fields) {
      // Identify the nodes.
      if ($entity->getEntityTypeId() === 'node') {
        $nodes = [$entity->id()];
      }
      else {
        $column = match ($entity->getEntityTypeId()) {
          'media' => 'mid',
          'file' => 'fid',
        };
        $nodes = array_unique(
          $this->database->select(LUTGeneratorInterface::TABLE_NAME, 'lut')
            ->fields('lut', ['nid'])
            ->condition("lut.{$column}", $entity->id())
            ->execute()
            ->fetchCol()
        );
      }

      foreach ($embargo_node_fields as $field) {
        foreach ($nodes as $node_id) {
          $field->addValue($node_id);
        }
      }
    }

  }

  /**
   * {@inheritDoc}
   */
  public function preIndexSave() : void {
    parent::preIndexSave();

    $this->ensureField(NULL, 'embargo_node', 'integer');

    $this->ensureField('entity:embargo', 'id', 'integer');
    $this->ensureField('entity:embargo', 'embargoed_node:entity:nid', 'integer');
    $this->ensureField('entity:embargo', 'embargo_type', 'integer');
    $this->ensureField('entity:embargo', 'expiration_date', 'date');
    $this->ensureField('entity:embargo', 'expiration_type', 'integer');
    $this->ensureField('entity:embargo', 'exempt_ips:entity:id', 'integer');
    $this->ensureField('entity:embargo', 'exempt_users:entity:uid', 'integer');
  }

  /**
   * {@inheritDoc}
   */
  public function preprocessSearchQuery(QueryInterface $query) : void {
    assert($query instanceof RefinableCacheableDependencyInterface);
    $query->addCacheContexts(['user.permissions']);
    if ($this->currentUser->hasPermission('bypass embargo access')) {
      return;
    }

    $query->addTag('embargo_join_processor');
  }

}
