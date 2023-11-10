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
    // Get node id.
    $item_id = $item->getId();
    $nodeId = preg_replace('/^entity:node\/(\d+):en$/', '$1', $item_id);

    // Based on node object get applicable Embargoes.
    $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
    $storages = $this->storage->getApplicableEmbargoes($node);

    if (empty($storages)) {
      return;
    }

    // Get IP range in CIDR format.
    foreach ($storages as $embargo) {
      foreach ($embargo->getExemptIps()->getRanges() as $range) {
        $ip = $range['value'];
      }
    }

    // Currently getting error for multivalued field.
    // "multiple values encountered for non multiValued field ss_embargo_ip".
    // So adding single value.
    //foreach ($ips as $ip) {
    $item->getField('embargo_ip')->addValue($ip);
    //}
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessSearchQuery(QueryInterface $query) {
    $currentUserIp = $this->requestStack->getCurrentRequest()->getClientIp();

    if ($currentUserIp) {
      // For testing only.
      $currentUserIp = '172.18.0.186';
      // todo: add current user IP.
      // Convert User IP into CIDR format query matching.
      $currentUserIpCidr = $this->ipToCidr($currentUserIp);

      // Add the condition to check if the user's IP is in the list using CIDR notation.
      $conditions = $query->getConditionGroup();
      $conditions->addCondition('embargo_ip', $currentUserIpCidr);

      // Query logging for validation.
      \Drupal::logger('embargo')->notice('Query altered: @query', ['@query' => (string) $query]);

    }
  }

  function ipToCidr($ip, $subnetMask = 24) {
    $ipParts = explode('.', $ip);

    // Ensure subnet mask is within valid range (0-32)
    $subnetMask = max(0, min(32, $subnetMask));

    // Calculate the network portion of the IP based on the subnet mask
    $network = implode('.', array_slice($ipParts, 0, floor($subnetMask / 8)));

    // Build the CIDR notation
    $cidr = $network . '.0/' . $subnetMask;

    return $cidr;
  }

}
