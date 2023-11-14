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
 * A search_api_solr processor to filter results based on embargo and user IP.
 *
 * @SearchApiProcessor(
 *   id = "embargo_ip_restriction",
 *   label = @Translation("Embargo IP Restriction"),
 *   description = @Translation("Processor to filter results based on embargo and user IP."),
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
      'description' => $this->t('Stores the Embargo IPs for filtering.'),
      'type' => 'string',
      'processor_id' => $this->getPluginId(),
      'stored' => TRUE,
      'indexed' => TRUE,
      'multiValued' => TRUE,
      'is_list' => TRUE,
    ]);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    // Get node ID.
    $item_id = $item->getId();
    $nodeId = preg_replace('/^entity:node\/(\d+):en$/', '$1', $item_id);

    // Load node based on ID, and get embargo.
    $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
    $storages = $this->storage->getApplicableEmbargoes($node);

    if (empty($storages)) {
      return;
    }

    // Get embargo IPs.
    $embargoIps = [];
    foreach ($storages as $embargo) {
      foreach ($embargo->getExemptIps()->getRanges() as $range) {
        $embargoIps[] = $range['value'];
      }
    }

    // Add embargo IPs in indexing data.
    $item->getField('embargo_ip')->setValues($embargoIps);
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessSearchQuery(QueryInterface $query) {
    $currentUserIp = $this->requestStack->getCurrentRequest()->getClientIp();

    if ($currentUserIp) {
      // Convert User IP into CIDR format query matching.
      $currentUserIpCidr = $this->ipToCidr($currentUserIp);

      // Add the condition to check if the user's IP is in the list using CIDR notation
      $conditions = $query->getConditionGroup();
      $conditions->addCondition('embargo_ip', $currentUserIpCidr);
    }
  }

  /**
   * Converts an IP address to CIDR notation.
   *
   * @param string $ip
   *   The IP address to convert.
   * @param int $subnetMask
   *   The subnet mask (default is 24).
   *
   * @return string
   *   The CIDR notation representation of the IP address.
   */
  function ipToCidr($ip, $subnetMask = 24) {
    // Check if the IP is IPv6.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
      // For simplicity, let's assume a default subnet mask of 128 for IPv6
      return $ip . '/128';
    }

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
