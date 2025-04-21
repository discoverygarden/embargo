<?php

namespace Drupal\reindex_embargoes\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerBase;
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
   * @var \Drupal\Component\Datetime\Time
   */
  protected TimeInterface $time;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);

    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->time = $container->get('datetime.time');
    $instance->queueFactory = $container->get('queue');
    $instance->configFactory = $container->get('config.factory');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $embargo = $this->entityTypeManager->getStorage('embargo')->load($data['embargo_id']);
    if (!$embargo) {
      return;
    }

    $current_time = $this->time->getRequestTime();
    $current_expiration = $embargo->getExpirationDate()->getTimestamp();

    if ($current_expiration > $current_time + 86400) {
      return;
    }

    if ($current_expiration > $current_time && $current_expiration < $current_time + 86400) {
      $this->queueFactory->get('embargo_expiration_reindex')->createItem($data);
      return;
    }

    $embargoed_node = $embargo->getEmbargoedNode();
    if ($embargoed_node) {
      $config = $this->configFactory->get('reindex_embargoes.settings');
      $selected_indexes = array_values($config->get('selected_indexes') ?? []);
      foreach ($selected_indexes as $index_id) {
        $index = $this->entityTypeManager->getStorage('search_api_index')->load($index_id);
        if ($index && $index->isServerEnabled() && $index->getDatasource('entity:node')) {
          $doc_ids = [];
          foreach ($embargoed_node->getTranslationLanguages() as $language) {
            $doc_ids[] = $embargoed_node->id() . ':' . $language->getId();
          }
          $index->trackItemsUpdated('entity:node', $doc_ids);
        }
      }
    }
  }
  
}
