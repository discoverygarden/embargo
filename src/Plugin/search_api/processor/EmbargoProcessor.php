<?php

namespace Drupal\embargo\Plugin\search_api\processor;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\embargo\EmbargoInterface;
use Drupal\embargo\Plugin\search_api\processor\Property\ListableEntityProcessorProperty;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility\Utility;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A search_api processor to add embargo related info.
 *
 * @SearchApiProcessor(
 *   id = "embargo_processor",
 *   label = @Translation("Embargo access (deprecated)"),
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
class EmbargoProcessor extends ProcessorPluginBase implements ContainerFactoryPluginInterface {

  const ENTITY_TYPES = ['file', 'media', 'node'];

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The currently logged-in user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Drupal's time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);

    $instance->requestStack = $container->get('request_stack');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->currentUser = $container->get('current_user');
    $instance->time = $container->get('datetime.time');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) : array {
    $properties = [];

    if ($datasource === NULL) {
      return $properties;
    }

    $properties['embargo'] = ListableEntityProcessorProperty::create('embargo')
      ->setList()
      ->setProcessorId($this->getPluginId());

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

    $datasource_id = $item->getDatasourceId();

    /** @var \Drupal\search_api\Item\FieldInterface[][][] $to_extract */
    $to_extract = [];
    foreach ($item->getFields(FALSE) as $field) {
      $property_path = $field->getPropertyPath();
      [$direct, $nested] = Utility::splitPropertyPath($property_path, FALSE);
      if ($field->getDatasourceId() === $datasource_id
        && $direct === 'embargo') {
        $to_extract[$nested][] = $field;
      }
    }

    /** @var \Drupal\embargo\EmbargoStorageInterface $embargo_storage */
    $embargo_storage = $this->entityTypeManager->getStorage('embargo');
    $embargoes = $embargo_storage->getApplicableEmbargoes($entity);
    $relevant_embargoes = array_filter(
      $embargoes,
      function (EmbargoInterface $embargo) use ($entity) {
        return in_array($embargo->getEmbargoType(), match ($entity->getEntityTypeId()) {
          'file', 'media' => [EmbargoInterface::EMBARGO_TYPE_FILE, EmbargoInterface::EMBARGO_TYPE_NODE],
          'node' => [EmbargoInterface::EMBARGO_TYPE_NODE],
        });
      }
    );

    foreach ($relevant_embargoes as $embargo) {
      $this->getFieldsHelper()->extractFields($embargo->getTypedData(), $to_extract);
    }

  }

  /**
   * {@inheritDoc}
   */
  public function preIndexSave() : void {
    parent::preIndexSave();

    /** @var \Drupal\Core\Entity\EntityFieldManagerInterface $field_manager */
    $field_manager = \Drupal::service('entity_field.manager');
    $base_field_definitions = $field_manager->getBaseFieldDefinitions('embargo');

    $ensure_label = function (FieldInterface $field) use ($base_field_definitions) {
      if ($field->getLabel() === NULL) {
        $label_pieces = ['Embargo:'];

        $path_components = explode(IndexInterface::PROPERTY_PATH_SEPARATOR, $field->getPropertyPath(), 3);
        $base_field = $base_field_definitions[$path_components[1]];
        $label_pieces[] = $base_field->getLabel();

        if (is_a($base_field->getClass(), EntityReferenceFieldItemListInterface::class, TRUE)) {
          $label_pieces[] = 'Entity';
          $label_pieces[] = 'ID';
        }
        $field->setLabel(implode(' ', $label_pieces));
      }
      return $field;
    };

    foreach ($this->index->getDatasources() as $datasource_id => $datasource) {
      if (!in_array($datasource->getEntityTypeId(), static::ENTITY_TYPES)) {
        continue;
      }

      $fields = [
        $this->ensureField($datasource_id, 'embargo:id', 'integer'),
        $this->ensureField($datasource_id, 'embargo:embargo_type', 'integer'),
        $this->ensureField($datasource_id, 'embargo:expiration_date', 'date'),
        $this->ensureField($datasource_id, 'embargo:expiration_type', 'integer'),
        $this->ensureField($datasource_id, 'embargo:exempt_ips:entity:id', 'integer'),
        $this->ensureField($datasource_id, 'embargo:exempt_users:entity:uid', 'integer'),
      ];
      array_map($ensure_label, $fields);
    }
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

    $datasources = $query->getIndex()->getDatasources();
    /** @var \Drupal\search_api\Datasource\DatasourceInterface[] $applicable_datasources */
    $applicable_datasources = array_filter($datasources, function (DatasourceInterface $datasource) {
      return in_array($datasource->getEntityTypeId(), static::ENTITY_TYPES);
    });
    if (empty($applicable_datasources)) {
      return;
    }

    $and_group = $query->createAndAddConditionGroup(tags: [
      'embargo_processor',
      'embargo_access',
    ]);
    foreach (array_keys($applicable_datasources) as $datasource_id) {
      if ($filter = $this->addEmbargoFilters($datasource_id, $query)) {
        $and_group->addConditionGroup($filter);
      }
    }
  }

  /**
   * Add embargo filters to the given query, for the given datasource.
   *
   * @param string $datasource_id
   *   The ID of the datasource for which to add filters.
   * @param \Drupal\search_api\Query\QueryInterface $query
   *   The query to which to add filters.
   */
  protected function addEmbargoFilters(string $datasource_id, QueryInterface $query) : ?ConditionGroupInterface {
    assert($query instanceof RefinableCacheableDependencyInterface);
    $or_group = $query->createConditionGroup('OR', [
      "embargo:$datasource_id",
    ]);

    // No embargo.
    if ($field = $this->findField($datasource_id, 'embargo:id')) {
      $or_group->addCondition($field->getFieldIdentifier(), NULL);
      $query->addCacheTags(['embargo_list']);
    }

    // Embargo duration/schedule.
    if ($expiration_type_field = $this->findField($datasource_id, 'embargo:expiration_type')) {
      $schedule_group = $query->createConditionGroup(tags: ['embargo_schedule']);
      // No indefinite embargo.
      $schedule_group->addCondition($expiration_type_field->getFieldIdentifier(), EmbargoInterface::EXPIRATION_TYPE_INDEFINITE, '<>');

      // Scheduled embargo in the past and none in the future.
      if ($scheduled_field = $this->findField($datasource_id, 'embargo:expiration_date')) {
        $schedule_group->addCondition($expiration_type_field->getFieldIdentifier(), EmbargoInterface::EXPIRATION_TYPE_SCHEDULED);
        // Embargo in the past.
        $schedule_group->addCondition($scheduled_field->getFieldIdentifier(), date('Y-m-d', $this->time->getRequestTime()), '<=');
        // No embargo in the future.
        $schedule_group->addCondition($scheduled_field->getFieldIdentifier(), [
          0 => date('Y-m-d', strtotime('+1 DAY', $this->time->getRequestTime())),
          1 => date('Y-m-d', PHP_INT_MAX),
        ], 'NOT BETWEEN');
        // Cacheable up to a day.
        $query->mergeCacheMaxAge(24 * 3600);
      }

      $or_group->addConditionGroup($schedule_group);
    }

    if ($this->currentUser->isAnonymous()) {
      $query->addCacheContexts(['user.roles:anonymous']);
    }
    elseif ($field = $this->findField($datasource_id, 'embargo:exempt_users:entity:uid')) {
      $or_group->addCondition($field->getFieldIdentifier(), $this->currentUser->id());
      $query->addCacheContexts(['user']);
    }

    if ($field = $this->findField($datasource_id, 'embargo:exempt_ips:entity:id')) {
      /** @var \Drupal\embargo\IpRangeStorageInterface $ip_range_storage */
      $ip_range_storage = $this->entityTypeManager->getStorage('embargo_ip_range');
      foreach ($ip_range_storage->getApplicableIpRanges($this->requestStack->getCurrentRequest()
        ->getClientIp()) as $ipRange) {
        $or_group->addCondition($field->getFieldIdentifier(), $ipRange->id());
        $query->addCacheableDependency($ipRange);
      }
      $query->addCacheContexts(['ip.embargo_range']);
    }

    return (count($or_group->getConditions()) > 0) ? $or_group : NULL;
  }

}
