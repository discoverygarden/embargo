<?php

namespace Drupal\reindex_embargoes\Drush\Commands;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Component\Datetime\TimeInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A Drush command file for reindex_embargoes.
 */
class ReindexEmbargoesCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * ReindexEmbargoesCommands constructor.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'queue')]
    protected QueueFactory $queueFactory,
    #[Autowire(service: 'datetime.time')]
    protected TimeInterface $time,
  ) {
    parent::__construct();
  }

  /**
   * Populates the queue with existing future-dated embargoes.
   */
  #[CLI\Command(name: 'reindex_embargoes:populate-queue', aliases: ['r_e:pq'])]
  public function populateQueue(): void {
    $this->output()->writeln("Populating Embargo expiration queue...");

    $embargo_storage = $this->entityTypeManager->getStorage('embargo');
    $queue = $this->queueFactory->get('embargo_expiration_reindex', TRUE);
    $current_timestamp = $this->time->getRequestTime();

    $current_iso = gmdate('Y-m-d', $current_timestamp);

    $query = $embargo_storage->getQuery()
      ->accessCheck(FALSE)
      ->exists('embargoed_node')
      ->condition('expiration_type', 1, '=')
      ->exists('expiration_date')
      ->condition('expiration_date', $current_iso, '>');

    $embargo_ids = $query->execute();

    if (empty($embargo_ids)) {
      $this->output()->writeln("No future-dated Embargoes found to queue.");
      return;
    }

    $this->output()->writeln(dt("Found @count Embargoes with future expiration dates. Processing...", ['@count' => count($embargo_ids)]));

    $embargoes = $embargo_storage->loadMultiple($embargo_ids);
    $queued_count = 0;

    foreach ($embargoes as $embargo) {
      $item = ['embargo_id' => $embargo->id()];
      $queue->createItem($item);
      $queued_count++;
    }

    $this->output()->writeln(dt("Successfully queued @count Embargoes for future re-indexing.", ['@count' => $queued_count]));
  }

}
