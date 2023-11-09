<?php

namespace Drupal\embargo\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\search_api\Query\QueryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A search_api_solr processor to filter search results based on user IP.
 *
 * @SearchApiProcessor(
 *   id = "embargo_ip_restriction",
 *   label = @Translation("Embargo IP Restriction"),
 *   description = @Translation("Combines allowed IP index and current user IP query processors."),
 *   stages = {
 *      "add_properties" = -10,
 *      "preprocess_query" = 20,
 *   }
 * )
 */
class EmbargoIpRestriction extends ProcessorPluginBase implements ContainerFactoryPluginInterface{

  // Config :
  // 1. Select `Embargo IP Restriction` at `admin/config/search/search-api/index/default_solr_index/processors`
  // 2. Add `embargo_ip` field under content.
  // Run indexing.

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Embargo entity storage.
   *
   * @var \Drupal\embargo\EmbargoStorageInterface
   */
  protected $storage;

  /**
   * Constructs a new instance of the class.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *    The request stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity Type Manager.
   *
   * @throws \Drupal\facets\Exception\InvalidProcessorException
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition,  RequestStack $request_stack, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
    $this->storage = $entity_type_manager->getStorage('embargo');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('request_stack'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    // Define the Embargo IP property.
    $properties['embargo_ip'] = new ProcessorProperty([
      'label' => $this->t('Embargo IP'),
      'description' => $this->t('Stores the IPs for embargo filtering.'),
      'type' => 'string',
      'processor_id' => $this->getPluginId(),
    ]);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    // Hard coded IP for testing.
    // todo: Fetch embargoes IPs, and look into multi-value field.
    $ips = '192.168.0.1';

    // Currently getting error for multivalued field.
    // "multiple values encountered for non multiValued field ss_embargo_ip".
    // So adding single value.
    //foreach ($ips as $ip) {
      $item->getField('embargo_ip')->addValue($ips);
    //}
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessSearchQuery(QueryInterface $query) {
    $currentUserIp = $this->requestStack->getCurrentRequest()->getClientIp();

    if ($currentUserIp) {
      // Add a filter condition for the current user's IP.
      // Adding hardcoded IP for testing.
      // Changing hard coded value impacting the result, so
      // query processing is working.
      // todo: add current user IP.
      $conditions = $query->getConditionGroup();
      $conditions->addCondition('embargo_ip', '192.168.0.2');
    }
  }

}
