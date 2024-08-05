<?php

namespace Drupal\embargo\Plugin\search_api\processor;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\embargo\EmbargoInterface;
use Drupal\islandora_hierarchical_access\LUTGeneratorInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A search_api processor to add embargo related info.
 *
 * @SearchApiProcessor(
 *   id = "embargo_join_processor",
 *   label = @Translation("Embargo access, join-wise"),
 *   description = @Translation("Add information regarding embargo access constraints."),
 *   stages = {
 *     "add_properties" = 0,
 *     "pre_index_save" = 0,
 *     "preprocess_query" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class EmbargoJoinProcessor extends ProcessorPluginBase implements ContainerFactoryPluginInterface {

  const ENTITY_TYPES = ['file', 'media', 'node'];
  const ALL_ENTITY_TYPES = ['file', 'media', 'node', 'embargo'];

  const NODE_FIELD = 'embargo__node';
  const EMBARGO_FIELD_NODE = 'embargo__node__node';
  const EMBARGO_FIELD_FILE = 'embargo__node__file';

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
   * Drupal's entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Symfony's request stack info.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);

    $instance->currentUser = $container->get('current_user');
    $instance->database = $container->get('database');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->requestStack = $container->get('request_stack');

    return $instance;
  }

  /**
   * {@inheritDoc}
   */
  public static function supportsIndex(IndexInterface $index) {
    return parent::supportsIndex($index) &&
      in_array('entity:embargo', $index->getDatasourceIds()) &&
      array_intersect(
        $index->getDatasourceIds(),
        array_map(function (string $type) {
          return "entity:{$type}";
        }, static::ENTITY_TYPES)
      );
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) : array {
    $properties = [];

    if ($datasource === NULL) {
      // Represent the node(s) to which a general content entity is associated.
      $properties[static::NODE_FIELD] = new ProcessorProperty([
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
        'is_computed' => TRUE,
      ]);
      // Represent the node of which a "file" embargo is associated.
      $properties[static::EMBARGO_FIELD_FILE] = new ProcessorProperty([
        'processor_id' => $this->getPluginId(),
        'is_list' => FALSE,
        'is_computed' => TRUE,
      ]);
      // Represent the node of which a "node" embargo is associated.
      $properties[static::EMBARGO_FIELD_NODE] = new ProcessorProperty([
        'processor_id' => $this->getPluginId(),
        'is_list' => FALSE,
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
    if (!in_array($item->getDatasource()->getEntityTypeId(), static::ALL_ENTITY_TYPES)) {
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

    if (in_array($item->getDatasource()->getEntityTypeId(), static::ENTITY_TYPES)) {
      $this->doAddNodeField($item, $entity);
    }
    else {
      $this->doAddEmbargoField($item, $entity);
    }

  }

  /**
   * Find the nodes related to the given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity in question.
   *
   * @return string[]|int[]
   *   The IDs of the related nodes.
   */
  protected function findRelatedNodes(EntityInterface $entity) : array {
    if ($entity->getEntityTypeId() === 'node') {
      return [$entity->id()];
    }
    else {
      $column = match ($entity->getEntityTypeId()) {
        'media' => 'mid',
        'file' => 'fid',
      };
      return $this->database->select(LUTGeneratorInterface::TABLE_NAME, 'lut')
        ->fields('lut', ['nid'])
        ->condition("lut.{$column}", $entity->id())
        ->execute()
        ->fetchCol();
    }
  }

  /**
   * Helper; build out field(s) for general content entities.
   *
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The item being indexed.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The content entity of the item being indexed.
   */
  protected function doAddNodeField(ItemInterface $item, EntityInterface $entity) : void {
    $embargo_node_fields = $this->getFieldsHelper()->filterForPropertyPath($item->getFields(FALSE), NULL, static::NODE_FIELD);
    if ($embargo_node_fields) {
      $nodes = array_unique($this->findRelatedNodes($entity));

      foreach ($embargo_node_fields as $field) {
        foreach ($nodes as $node_id) {
          $field->addValue($node_id);
        }
      }
    }
  }

  /**
   * Helper; build out field(s) for embargo entities, specifically.
   *
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The item being indexed.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The content entity of the item being indexed.
   */
  protected function doAddEmbargoField(ItemInterface $item, EntityInterface $entity) : void {
    assert($entity instanceof EmbargoInterface);

    // Early return if there is no embargoed node.
    if ($entity->getEmbargoedNode() === NULL) {
      return;
    }

    $paths = match ($entity->getEmbargoType()) {
      EmbargoInterface::EMBARGO_TYPE_FILE => [static::EMBARGO_FIELD_FILE],
      EmbargoInterface::EMBARGO_TYPE_NODE => [static::EMBARGO_FIELD_NODE, static::EMBARGO_FIELD_FILE],
    };

    $fields = $item->getFields(FALSE);
    foreach ($paths as $path) {
      $target_fields = $this->getFieldsHelper()->filterForPropertyPath($fields, NULL, $path);
      foreach ($target_fields as $target_field) {
        $target_field->addValue($entity->getEmbargoedNode()->id());
      }
    }
  }

  /**
   * {@inheritDoc}
   */
  public function preIndexSave() : void {
    $this->ensureField(NULL, static::NODE_FIELD, 'integer');
    $this->ensureField(NULL, static::EMBARGO_FIELD_FILE, 'integer');
    $this->ensureField(NULL, static::EMBARGO_FIELD_NODE, 'integer');

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

    $queries = [];

    if (in_array('entity:node', $this->index->getDatasourceIds())) {
      $queries['node'] = [
        'data sources' => ['entity:node'],
        'embargo path' => static::EMBARGO_FIELD_NODE,
        'node path' => static::NODE_FIELD,
      ];
    }
    if ($intersection = array_intersect($this->index->getDatasourceIds(), ['entity:media', 'entity:file'])) {
      $queries['file'] = [
        'data sources' => $intersection,
        'embargo path' => static::EMBARGO_FIELD_FILE,
        'node path' => static::NODE_FIELD,
      ];
    }

    if (!$queries) {
      return;
    }

    /** @var \Drupal\embargo\IpRangeInterface[] $ip_range_entities */
    $ip_range_entities = $this->entityTypeManager->getStorage('embargo_ip_range')
      ->getApplicableIpRanges($this->requestStack->getCurrentRequest()->getClientIp());

    $query->addCacheContexts([
      // Caching by groups of ranges instead of individually should promote
      // cacheability.
      'ip.embargo_range',
      // Exemptable users, so need to deal with them.
      'user.embargo__has_exemption',
    ]);
    // Embargo dates deal with granularity to the day.
    $query->mergeCacheMaxAge(24 * 3600);

    $types = ['embargo', 'embargo_ip_range', 'media', 'file', 'node'];
    foreach ($types as $type) {
      /** @var \Drupal\Core\Entity\EntityTypeInterface $entity_type */
      $entity_type = $this->entityTypeManager->getDefinition($type);
      $query->addCacheTags($entity_type->getListCacheTags());
    }

    $query->addTag('embargo_join_processor');
    $query->setOption('embargo_join_processor__ip_ranges', $ip_range_entities);
    $query->setOption('embargo_join_processor__queries', $queries);
  }

}
