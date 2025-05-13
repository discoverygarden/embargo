<?php

namespace Drupal\reindex_embargoes\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\search_api\IndexInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * QueueWorker for reindexing embargoed nodes.
 *
 * @QueueWorker(
 *   id = "embargo_expiration_reindex",
 *   title = @Translation("Embargo Expiration Reindex"),
 *   cron = {"time" = 60}
 * )
 */
class EmbargoExpirationReindex extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    TimeInterface $time,
    ConfigFactoryInterface $config_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->time = $time;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!isset($data['embargo_id'])) {
      return;
    }

    $embargo = $this->entityTypeManager->getStorage('embargo')->load($data['embargo_id']);
    if (!$embargo) {
      return;
    }

    $expiration_date_object = $embargo->getExpirationDate();
    if (!$expiration_date_object || !$expiration_date_object->getTimestamp()) {
      return;
    }

    $expiration_timestamp = $expiration_date_object->getTimestamp();
    $current_timestamp = $this->time->getRequestTime();

    // If the expiration time is still in the future, requeue it with a delay.
    if ($expiration_timestamp > $current_timestamp) {
      $delay = $expiration_timestamp - $current_timestamp;
      throw new DelayedRequeueException($delay, "Embargo ID {$embargo->id()} not yet expired. Requeuing.");
    }

    $embargoed_node = $embargo->getEmbargoedNode();
    if ($embargoed_node) {
      $config = $this->configFactory->get('reindex_embargoes.settings');
      $selected_indexes = array_values($config->get('selected_indexes') ?? []);

      foreach ($selected_indexes as $index_id) {
        $index = $this->entityTypeManager->getStorage('search_api_index')->load($index_id);
        if ($index instanceof IndexInterface && $index->isServerEnabled() && $index->status()) {
          if ($index->isValidDatasource('entity:node')) {
            $node_ids_to_reindex = [];
            // Item ID format is typically 'entity_id:langcode'.
            foreach ($embargoed_node->getTranslationLanguages() as $language) {
              $node_ids_to_reindex[] = $embargoed_node->id() . ':' . $language->getId();
            }

            if (!empty($node_ids_to_reindex)) {
              $index->trackItemsUpdated('entity:node', $node_ids_to_reindex);
            }
          }
        }
      }
    }
  }

}
