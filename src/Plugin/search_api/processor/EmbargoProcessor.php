<?php

namespace Drupal\embargo\Plugin\search_api\processor;

use Drupal\embargo\EmbargoInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Plugin\search_api\processor\Property\CustomValueProperty;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A search_api_solr processor to add embargo related info.
 *
 * @SearchApiProcessor(
 *   id = "embargo_processor",
 *   label = @Translation("Embargo Processor"),
 *   description = @Translation("Processor to add information to the index related to Embargo."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class EmbargoProcessor extends ProcessorPluginBase implements ContainerFactoryPluginInterface {
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
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Entity Type Manager.
   *
   * @throws \Drupal\facets\Exception\InvalidProcessorException
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if ($datasource) {
      $definition = [
        'label' => $this->t('Embargo Status'),
        'description' => $this->t('Field to pass embargo status to solr index.'),
        'type' => 'string',
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
      ];
      $properties['embargo_status'] = new CustomValueProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $embargo_expiration_type = $embargo_type = [];

    // Get node ID.
    $item_id = $item->getId();
    $nodeId = preg_replace('/^entity:node\/(\d+):en$/', '$1', $item_id);

    // Load node based on ID, and get embargo.
    $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
    $storages = $this->storage->getApplicableEmbargoes($node);

    if (empty($storages)) {
      return;
    }

    // Get Embargo details and prepare to pass it to index field.
    foreach ($storages as $embargo) {
      $allowed_expiration_types = EmbargoInterface::EXPIRATION_TYPES;
      $allowed_embargo_type = EmbargoInterface::EMBARGO_TYPES;
      // Get Embargo Type.
      $embargo_type[] = 'embargo-type-' . $allowed_embargo_type[$embargo->getEmbargoType()];

      // Get Expiration Type.
      $embargo_expiration_type[] = 'embargo-expiration-type-' . $allowed_expiration_types[$embargo->getExpirationType()];
    }

    $embargo_status = $combinedString = implode(' ', $embargo_type) . ' ' . implode(' ', $embargo_expiration_type);

    $fields = $this->getFieldsHelper()
      ->filterForPropertyPath($item->getFields(), $item->getDatasourceId(), 'embargo_status');
    foreach ($fields as $field) {
      $field->addValue($embargo_status);
    }
  }

}
