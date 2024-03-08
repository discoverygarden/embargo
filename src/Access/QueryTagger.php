<?php

namespace Drupal\embargo\Access;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\embargo\EmbargoExistenceQueryTrait;
use Drupal\embargo\EmbargoInterface;
use Drupal\islandora_hierarchical_access\Access\QueryConjunctionTrait;
use Drupal\islandora_hierarchical_access\TaggedTargetsTrait;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles tagging entity queries with access restrictions for embargoes.
 */
class QueryTagger {

  use EmbargoExistenceQueryTrait;
  use QueryConjunctionTrait;
  use TaggedTargetsTrait;

  /**
   * Constructor.
   */
  public function __construct(
    AccountProxyInterface $user,
    RequestStack $request_stack,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    TimeInterface $time,
    DateFormatterInterface $date_formatter
  ) {
    $this->user = $user;
    $this->currentIp = $request_stack->getCurrentRequest()->getClientIp();
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->time = $time;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * Builds up the conditions used to restrict media or nodes.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query being executed.
   */
  public function tagNode(SelectInterface $query) : void {
    if ($query->hasTag('islandora_hierarchical_access_subquery')) {
      // Being run as a subquery: We do not want to touch it as we expect our
      // IslandoraHierarchicalAccessEventSubscriber class to deal with it.
      return;
    }
    if ($this->user->hasPermission('bypass embargo access')) {
      return;
    }
    $type = 'node';

    static::conjunctionQuery($query);

    $tagged_table_aliases = $query->getMetaData('embargo_tagged_table_aliases') ?? [];

    $target_aliases = $this->getTaggingTargets($query, $tagged_table_aliases, $type);

    if (empty($target_aliases)) {
      return;
    }

    $query->addMetaData('embargo_tagged_table_aliases', $tagged_table_aliases);
    $existence_query = $query->getMetaData('embargo_tagged_existence_query');

    if (!$existence_query) {
      $existence_query = $this->database->select('node', 'existence_node');
      $existence_query->fields('existence_node', ['nid']);
      $query->addMetaData('embargo_tagged_existence_query', $existence_query);

      $query->exists($existence_query);
    }

    $existence_query->where(strtr('!field IN (!targets)', [
      '!field' => 'existence_node.nid',
      '!targets' => implode(', ', $target_aliases),
    ]));

    if (!$query->hasTag('embargo_access')) {
      $query->addTag('embargo_access');

      $existence_query->condition($existence_condition = $existence_query->andConditionGroup())
        ->addMetaData('embargo_existence_condition', $existence_condition);

      $this->applyExistenceQuery(
        $existence_query,
        $existence_condition,
        'existence_node',
        [EmbargoInterface::EMBARGO_TYPE_NODE],
      );
    }
  }

}
