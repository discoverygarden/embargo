<?php

namespace Drupal\embargo\Plugin\search_api\processor;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\embargo\EmbargoInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\QueryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A search_api processor to add embargo related info.
 *
 * @SearchApiProcessor(
 *   id = "embargo_processor",
 *   label = @Translation("Embargo access"),
 *   description = @Translation("Add information regarding embargo access
 *   constraints."),
 *   stages = {
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
   * {@inheritDoc}
   */
  public function preIndexSave() {
    parent::preIndexSave();

    foreach ($this->index->getDatasources() as $datasource_id => $datasource) {
      if (!in_array($datasource->getEntityTypeId(), static::ENTITY_TYPES)) {
        continue;
      }

      $this->ensureField($datasource_id, 'embargo:entity:id', 'integer');
      $this->ensureField($datasource_id, 'embargo:entity:embargo_type', 'integer');
      $this->ensureField($datasource_id, 'embargo:entity:expiration_date', 'date');
      $this->ensureField($datasource_id, 'embargo:entity:expiration_type', 'integer');
      $this->ensureField($datasource_id, 'embargo:entity:exempt_ips:entity:id', 'integer');
      $this->ensureField($datasource_id, 'embargo:entity:exempt_users:entity:uid', 'integer');
    }
  }

  /**
   * {@inheritDoc}
   */
  public function preprocessSearchQuery(QueryInterface $query) : void {
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

    $and_group = $query->createAndAddConditionGroup(tags: ['embargo_processor', 'embargo_access']);
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
    $or_group = $query->createConditionGroup('OR', [
      "embargo:$datasource_id",
    ]);

    // No embargo.
    if ($field = $this->findField($datasource_id, 'embargo:entity:id')) {
      $or_group->addCondition($field->getFieldIdentifier(), NULL);
    }

    // Embargo durations.
    // No indefinite embargo.
    if ($expiration_type_field = $this->findField($datasource_id, 'embargo:entity:expiration_type')) {
      $or_group->addCondition($expiration_type_field->getFieldIdentifier(), EmbargoInterface::EXPIRATION_TYPE_INDEFINITE, '<>');

      // Scheduled embargo in the past and none in the future.
      if ($scheduled_field = $this->findField($datasource_id, 'embargo:entity:expiration_date')) {
        $schedule_group = $query->createConditionGroup(tags: ['embargo_scheduled']);

        $schedule_group->addCondition($expiration_type_field->getFieldIdentifier(), EmbargoInterface::EXPIRATION_TYPE_INDEFINITE, '<>');
        $schedule_group->addCondition($expiration_type_field->getFieldIdentifier(), EmbargoInterface::EXPIRATION_TYPE_SCHEDULED);
        // Embargo in the past.
        $schedule_group->addCondition($scheduled_field->getFieldIdentifier(), date('Y-m-d', $this->time->getRequestTime()), '<=');
        // No embargo in the future.
        $schedule_group->addCondition($scheduled_field->getFieldIdentifier(), [
          0 => date('Y-m-d', strtotime('+1 DAY', $this->time->getRequestTime())),
          1 => date('Y-m-d', PHP_INT_MAX),
        ], 'NOT BETWEEN');

        $or_group->addConditionGroup($schedule_group);
      }
    }

    if (
      !$this->currentUser->isAnonymous() &&
      ($field = $this->findField($datasource_id, 'embargo:entity:exempt_users:entity:uid'))
    ) {
      $or_group->addCondition($field->getFieldIdentifier(), $this->currentUser->id());
    }

    if ($field = $this->findField($datasource_id, 'embargo:entity:exempt_ips:entity:id')) {
      /** @var \Drupal\embargo\IpRangeStorageInterface $ip_range_storage */
      $ip_range_storage = $this->entityTypeManager->getStorage('embargo_ip_range');
      foreach ($ip_range_storage->getApplicableIpRanges($this->requestStack->getCurrentRequest()
        ->getClientIp()) as $ipRange) {
        $or_group->addCondition($field->getFieldIdentifier(), $ipRange->id());
      }
    }

    return (count($or_group->getConditions()) > 0) ? $or_group : NULL;
  }

}
