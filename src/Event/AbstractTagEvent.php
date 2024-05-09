<?php

namespace Drupal\embargo\Event;

use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Abstract base tag event class.
 */
abstract class AbstractTagEvent extends Event {

  /**
   * The build condition.
   *
   * @var \Drupal\Core\Database\Query\ConditionInterface
   */
  protected ConditionInterface $condition;

  /**
   * Constructor.
   */
  public function __construct(
    protected SelectInterface $query,
  ) {
    $this->condition = $this->query->orConditionGroup();
  }

  /**
   * Get the query upon which to act.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The query upon which we are to act.
   */
  public function getQuery() : SelectInterface {
    return $this->query;
  }

  /**
   * Get the current condition.
   *
   * @return \Drupal\Core\Database\Query\ConditionInterface
   *   The current condition.
   */
  public function getCondition() : ConditionInterface {
    return $this->condition;
  }

  /**
   * Get the base "embargo" table alias.
   *
   * @return string
   *   The base "embargo" alias, as used in the query.
   */
  public function getEmbargoAlias() : string {
    return $this->query->getMetaData('embargo_alias');
  }

  /**
   * Get the base query columns representing node IDs to find embargoes.
   *
   * @return string[]
   *   The column aliases representing node IDs.
   */
  public function getTargetAliases() : array {
    return $this->query->getMetaData('embargo_target_aliases');
  }

}
