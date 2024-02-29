<?php

namespace Drupal\embargo\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\embargo\EmbargoInterface;
use Drupal\embargo\Plugin\search_api\processor\Property\EmbargoInfoProperty;
use Drupal\islandora\IslandoraUtils;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A search_api processor to add embargo related info.
 *
 * @SearchApiProcessor(
 *   id = "embargo_processor",
 *   label = @Translation("Embargo access"),
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
   * Islandora utils helper service.
   *
   * XXX: Ideally, this would reference an interface; however, such does not
   * exist.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected IslandoraUtils $islandoraUtils;

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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);

    $instance->requestStack = $container->get('request_stack');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->islandoraUtils = $container->get('islandora.utils');
    $instance->currentUser = $container->get('current_user');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) : array {
    if ($datasource !== NULL) {
      return [];
    }

    return [
      'embargo_info' => new EmbargoInfoProperty([
        'label' => $this->t('Embargo info'),
        'description' => $this->t('Aggregated embargo info'),
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
        'computed' => TRUE,
      ]),
    ];
  }

  /**
   * Get the embargo(es) associated with the given index item.
   *
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The index item to consider.
   *
   * @return iterable
   *   A generated sequence of applicable embargoes.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function getEmbargoes(ItemInterface $item) : iterable {
    try {
      foreach ($this->entityTypeManager->getStorage('embargo')
        ->getApplicableEmbargoes($item->getOriginalObject()->getValue()) as $embargo) {
        yield $embargo;
      }
    }
    catch (SearchApiException) {
      // No-op; object probably did not exist?
    }
  }

  /**
   * {@inheritDoc}
   */
  public function preIndexSave() {
    parent::preIndexSave();

    foreach ($this->getPropertyDefinitions() as $base => $def) {
      if ($def instanceof ComplexDataDefinitionInterface) {
        foreach (array_keys($def->getPropertyDefinitions()) as $name) {
          $this->ensureField(NULL, "{$base}:{$name}");
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) : void {
    $source_type_id = $item->getDatasource()->getEntityTypeId();
    if (!in_array($source_type_id, static::ENTITY_TYPES)) {
      return;
    }

    $info = [
      'total_count' => 0,
      'indefinite_count' => 0,
      'scheduled_timestamps' => [],
      'exempt_users' => [],
      'exempt_ip_ranges' => [],
    ];

    // Get Embargo details and prepare to pass it to index field.
    foreach ($this->getEmbargoes($item) as $embargo) {
      if ($embargo->getEmbargoType() === EmbargoInterface::EMBARGO_TYPE_FILE && $source_type_id === 'node') {
        continue;
      }

      $info['total_count']++;
      if ($embargo->getExpirationType() === EmbargoInterface::EXPIRATION_TYPE_INDEFINITE) {
        $info['indefinite_count']++;
      }
      else {
        $info['scheduled_timestamps'][] = $embargo->getExpirationDate()->getTimestamp();
      }

      $info['exempt_users'] = array_merge(
        $info['exempt_users'],
        array_map(function (UserInterface $user) {
          return $user->id();
        }, $embargo->getExemptUsers()),
      );
      if ($range_id = $embargo->getExemptIps()?->id()) {
        $info['exempt_ip_ranges'][] = $range_id;
      }
    }

    foreach (['scheduled_timestamps', 'exempt_users', 'exempt_ip_ranges'] as $key) {
      $info[$key] = array_unique($info[$key]);
    }

    foreach ($info as $key => $val) {
      if ($field = $this->findField(NULL, "embargo_info:{$key}")) {
        $item_field = $item->getField($field->getFieldIdentifier(), FALSE);
        foreach ((is_array($val) ? $val : [$val]) as $value) {
          $item_field->addValue($value);
        }
      }
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
    $applicable_datasources = array_filter($datasources, function(DatasourceInterface $datasource) {
      return in_array($datasource->getEntityTypeId(), static::ENTITY_TYPES);
    });
    if (empty($applicable_datasources)) {
      return;
    }

    $or_group = $query->createConditionGroup('OR', [
      'embargo_processor',
      'embargo_access',
    ]);

    // No embargo.
    if ($field = $this->findField(NULL, 'embargo_info:total_count')) {
      $or_group->addCondition($field->getFieldIdentifier(), 0);
    }

    // Embargo durations.
    // No indefinite embargo.
    if ($indefinite_field = $this->findField(NULL, 'embargo_info:indefinite_count')) {
      $scheduled_group = $query->createConditionGroup(tags: [
        'embargo_scheduled',
      ]);
      $scheduled_group->addCondition($indefinite_field->getFieldIdentifier(), 0);

      // No scheduled embargo in the future.
      // XXX: Might not quite work? If there's a single scheduled embargo lesser, would it open it?
      if ($scheduled_field = $this->findField(NULL, 'embargo_info:scheduled_timestamps')) {
        $scheduled_group->addCondition($scheduled_field->getFieldIdentifier(), [
          0 => time() + 1,
          1 => PHP_INT_MAX,
        ], 'NOT BETWEEN');
      }
      $or_group->addConditionGroup($scheduled_group);
    }

    if (!$this->currentUser->isAnonymous() && ($field = $this->findField(NULL, 'embargo_info:exempt_users'))) {
      $or_group->addCondition($field->getFieldIdentifier(), $this->currentUser->id());
    }

    if ($field = $this->findField(NULL, 'embargo_info:exempt_ip_ranges')) {
      /** @var \Drupal\embargo\IpRangeStorageInterface $ip_range_storage */
      $ip_range_storage = $this->entityTypeManager->getStorage('embargo_ip_range');
      foreach ($ip_range_storage->getApplicableIpRanges($this->requestStack->getCurrentRequest()
        ->getClientIp()) as $ipRange) {
        $or_group->addCondition($field->getFieldIdentifier(), $ipRange->id());
      }
    }

    if (count($or_group->getConditions()) > 0) {
      $query->addConditionGroup($or_group);
    }
  }

}
