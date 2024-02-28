<?php

namespace Drupal\embargo\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\embargo\EmbargoInterface;
use Drupal\embargo\Plugin\search_api\processor\Property\EmbargoInfoProperty;
use Drupal\file\FileInterface;
use Drupal\islandora\IslandoraUtils;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Query\QueryInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A search_api_solr processor to add embargo related info.
 *
 * @SearchApiProcessor(
 *   id = "embargo_processor",
 *   label = @Translation("Embargo Processor"),
 *   description = @Translation("Processor to add information to the index related to Embargo."),
 *   stages = {
 *     "add_properties" = 0,
 *     "preprocess_query" = 10,
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

  protected RequestStack $requestStack;

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
    if (!$datasource || !in_array($datasource->getEntityTypeId(), static::ENTITY_TYPES)) {
      return [];
    }

    return [
      'embargo_info' => new EmbargoInfoProperty([
        'label' => $this->t('Embargo info'),
        'description' => $this->t('Aggregated embargo info'),
        'processor_id' => $this->getPluginId(),
        'is_list' => FALSE,
        'computed' => FALSE,
      ]),
    ];
  }

  /**
   * Get the node(s) associated with the given index item.
   *
   * @param \Drupal\search_api\Item\ItemInterface $item
   *   The index item to consider.
   *
   * @return iterable
   *   A generated sequence of nodes.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function getNodes(ItemInterface $item) : iterable {
    $original = $item->getOriginalObject();

    if ($original instanceof NodeInterface) {
      yield $original;
    }
    elseif ($original instanceof MediaInterface) {
      yield $this->islandoraUtils->getParentNode($original);
    }
    elseif ($original instanceof FileInterface) {
      foreach ($this->islandoraUtils->getReferencingMedia($original->id()) as $media) {
        yield $this->islandoraUtils->getParentNode($media);
      }
    }
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
    foreach ($this->getNodes($item) as $node) {
      foreach ($this->entityTypeManager->getStorage('embargo')->getApplicableEmbargoes($node) as $embargo) {
        yield $embargo;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $info = [
      'total_count' => 0,
      'indefinite_count' => 0,
      'scheduled_timestamps' => [],
      'exempt_users' => [],
      'exempt_ip_ranges' => [],
    ];

    $source_type_id = $item->getDatasource()->getEntityTypeId();
    if (!in_array($source_type_id, static::ENTITY_TYPES)) {
      return;
    }

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
//
//    $fields = $this->getFieldsHelper()
//      ->filterForPropertyPath($item->getFields(), $item->getDatasourceId(), 'embargo_info');
//    foreach ($fields as $field) {
//      $field->addValue($info);
//    }
    $this->ensureField($item->getDatasourceId(), 'embargo_info')->addValue($info);
  }

  /**
   * {@inheritDoc}
   */
  public function preprocessSearchQuery(QueryInterface $query) : void {
    $datasources = $query->getIndex()->getDatasources();
    /** @var \Drupal\search_api\Datasource\DatasourceInterface[] $applicable_datasources */
    $applicable_datasources = array_filter($datasources, function (DatasourceInterface $datasource) {
      return in_array($datasource->getEntityTypeId(), static::ENTITY_TYPES);
    });
    if (empty($applicable_datasources)) {
      return;
    }

    foreach ($applicable_datasources as $datasource) {
      //$datasource->get
    }

    $fields = $query->getIndex()->getFields();
    $or_group = $query->createConditionGroup('OR');

    // No embargo.
    $or_group->addCondition('embargo_info:total_count', 0);

    // Embargo durations.
    // No indefinite embargo.
    $or_group->addCondition('embargo_info:indefinite_count', 0);
    // No scheduled embargo in the future.
    // XXX: Might not quite work? If there's a single scheduled embargo lesser, would it open it?
    $or_group->addCondition('embargo_info:scheduled_timestamps', [
      0 => time() + 1,
      1 => PHP_INT_MAX,
    ], 'NOT BETWEEN');

    if (!$this->currentUser->isAnonymous()) {
      $or_group->addCondition('embargo_info:exempt_users', $this->currentUser->id());
    }

    /** @var \Drupal\embargo\IpRangeStorageInterface $ip_range_storage */
    $ip_range_storage = $this->entityTypeManager->getStorage('embargo_ip_range');
    foreach ($ip_range_storage->getApplicableIpRanges($this->requestStack->getCurrentRequest()->getClientIp()) as $ipRange) {
      //$or_group->addCondition('embargo_info:exempt_ip_ranges');
    }

    $query->addConditionGroup($or_group);
  }

}
