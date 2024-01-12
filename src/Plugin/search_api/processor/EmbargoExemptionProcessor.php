<?php

namespace Drupal\embargo\Plugin\search_api\processor;

use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\search_api\Query\QueryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A search_api_solr processor to filter results based on embargo and user IP.
 *
 * @SearchApiProcessor(
 *   id = "embargo_exemption_processor",
 *   label = @Translation("Embargo Exemption Processor"),
 *   description = @Translation("Processor to filter results based on embargo and user IP."),
 *   stages = {
 *      "add_properties" = -10,
 *      "preprocess_query" = 20,
 *   }
 * )
 */
class EmbargoExemptionProcessor extends ProcessorPluginBase implements ContainerFactoryPluginInterface {

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
   * Account Proxy object.
   *
   * @var Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

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
   *   The request stack.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity Type Manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The Account Proxy.
   *
   * @throws \Drupal\facets\Exception\InvalidProcessorException
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RequestStack $request_stack, EntityTypeManagerInterface $entity_type_manager, AccountProxyInterface $currentUser) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->requestStack = $request_stack;
    $this->entityTypeManager = $entity_type_manager;
    $this->storage = $entity_type_manager->getStorage('embargo');
    $this->currentUser = $currentUser;
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
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    // Define the Embargo IP property.
    $properties['embargo_exempt_ip_ranges'] = new ProcessorProperty([
      'label' => $this->t('Exempt IPs'),
      'description' => $this->t('Stores the IP ranges that are exempt from embargo.'),
      'type' => 'string',
      'processor_id' => $this->getPluginId(),
      'stored' => TRUE,
      'indexed' => TRUE,
      'multiValued' => TRUE,
      'is_list' => TRUE,
    ]);

    // Define the exempt users property.
    $properties['embargo_exempt_users'] = new ProcessorProperty([
      'label' => $this->t('Embargo exempt users'),
      'description' => $this->t('Stores the Exempt users IDs for filtering.'),
      'type' => 'integer',
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
    $exemptUsers = $embargoIps = [];

    // Get node ID.
    $item_id = $item->getId();
    $nodeId = preg_replace('/^entity:node\/(\d+):en$/', '$1', $item_id);

    // Load node based on ID, and get embargo.
    $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
    $storages = $this->storage->getApplicableEmbargoes($node);

    if (empty($storages)) {
      return;
    }

    foreach ($storages as $embargo) {
      // Get embargo IPs.
      if (!empty($embargo->getExemptIps())) {
        foreach ($embargo->getExemptIps()->getRanges() as $range) {
          $embargoIps[] = $range['value'];
        }
      }

      // Get exempt users IDs.
      if (!empty($embargo->getExemptUsers())) {
        foreach ($embargo->get('embargo_exempt_users')->getValue() as $user) {
          $exemptUsers[] = $user['target_id'];
        }
      }
    }

    // Add embargo IPs and exempt users IDs in indexing data.
    $item->getField('embargo_exempt_ip_ranges')->setValues($embargoIps);
    $item->getField('embargo_exempt_users')->setValues($exemptUsers);
  }

  /**
   * {@inheritdoc}
   */
  public function preprocessSearchQuery(QueryInterface $query) {
    $currentUserIp = $this->requestStack->getCurrentRequest()->getClientIp();
    $currentUserId = $this->currentUser->id();

    $conditions = $query->createConditionGroup('OR');

    if ($currentUserIp) {
      // Convert User IP into CIDR format query matching.
      $currentUserIpCidr = $this->ipToCidr($currentUserIp);

      // Add the condition to check if the user's IP
      // is in the list using CIDR notation.
      $conditions->addCondition('embargo_exempt_ip_ranges', $currentUserIpCidr);
      $conditions->addCondition('embargo_exempt_ip_ranges', NULL);
    }

    // Add condition for embargo_exempt_users.
    if ($currentUserId) {
      $conditions->addCondition('embargo_exempt_users', $currentUserId);
    }

    $query->addConditionGroup($conditions);
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
  public function ipToCidr($ip, $subnetMask = 24) {
    // Check if the IP is IPv6.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
      // For simplicity, let's assume a default subnet mask of 128 for IPv6.
      return $ip . '/128';
    }

    $ipParts = explode('.', $ip);

    // Ensure subnet mask is within valid range (0-32).
    $subnetMask = max(0, min(32, $subnetMask));

    // Calculate the network portion of the IP based on the subnet mask.
    $network = implode('.', array_slice($ipParts, 0, floor($subnetMask / 8)));

    // Build the CIDR notation.
    $cidr = $network . '.0/' . $subnetMask;

    return $cidr;
  }

}
