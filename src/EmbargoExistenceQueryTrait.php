<?php

namespace Drupal\embargo;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\embargo\Event\TagExclusionEvent;
use Drupal\embargo\Event\TagInclusionEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Helper trait; facilitate filtering of embargoed entities.
 */
trait EmbargoExistenceQueryTrait {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $user;

  /**
   * The IP of the request.
   *
   * @var string
   */
  protected ?string $currentIp;

  /**
   * Instance of a Drupal database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * Date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The event dispatcher service.
   *
   * @var \Symfony\Contracts\EventDispatcher\EventDispatcherInterface
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * Helper; apply existence checks to a node(-like) table.
   *
   * @param \Drupal\Core\Database\Query\ConditionInterface $existence_condition
   *   The condition object to which to add the existence check.
   * @param string[] $target_aliases
   *   The alias of the node-like table in the query to which to attach things.
   * @param array $embargo_types
   *   The types of embargo to deal with.
   */
  protected function applyExistenceQuery(
    ConditionInterface $existence_condition,
    array $target_aliases,
    array $embargo_types,
  ) : void {
    $existence_condition->condition(
      $existence_condition->orConditionGroup()
        ->notExists($this->getNullQuery($target_aliases, $embargo_types))
        ->exists($this->getAccessibleEmbargoesQuery($target_aliases, $embargo_types))
    );
  }

  /**
   * Set the event dispatcher service.
   *
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher service to set.
   *
   * @return \Drupal\embargo\EmbargoExistenceQueryTrait|\Drupal\embargo\Access\QueryTagger|\Drupal\embargo\EventSubscriber\IslandoraHierarchicalAccessEventSubscriber
   *   The current instance; fluent interface.
   */
  protected function setEventDispatcher(EventDispatcherInterface $event_dispatcher) : self {
    $this->eventDispatcher = $event_dispatcher;
    return $this;
  }

  /**
   * Get the event dispatcher service.
   *
   * @return \Symfony\Contracts\EventDispatcher\EventDispatcherInterface
   *   The event dispatcher service.
   */
  protected function getEventDispatch() : EventDispatcherInterface {
    return $this->eventDispatcher ?? \Drupal::service('event_dispatcher');
  }

  /**
   * Build out condition for matching embargo entities.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query in which the condition is to be attached.
   *
   * @return \Drupal\Core\Database\Query\ConditionInterface
   *   The condition to attach.
   */
  protected function buildInclusionBaseCondition(SelectInterface $query) : ConditionInterface {
    $dispatched_event = $this->getEventDispatch()->dispatch(new TagInclusionEvent($query));

    return $dispatched_event->getCondition();
  }

  /**
   * Build out condition for matching overriding embargo entities.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query in which the condition is to be attached.
   *
   * @return \Drupal\Core\Database\Query\ConditionInterface
   *   The condition to attach.
   */
  protected function buildExclusionBaseCondition(SelectInterface $query) : ConditionInterface {
    $dispatched_event = $this->getEventDispatch()->dispatch(new TagExclusionEvent($query));

    return $dispatched_event->getCondition();
  }

  /**
   * Get query for negative assertion.
   *
   * @param array $target_aliases
   *   The target aliases on which to match.
   * @param array $embargo_types
   *   The relevant types of embargoes to which to constrain.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The negative-asserting query.
   */
  protected function getNullQuery(array $target_aliases, array $embargo_types) : SelectInterface {
    $embargo_alias = 'embargo_null';
    $query = $this->database->select('embargo', $embargo_alias);
    $query->addExpression(1, 'embargo_null_e');
    $query->addMetaData('embargo_alias', $embargo_alias);
    $query->addMetaData('embargo_target_aliases', $target_aliases);

    $query->condition($this->buildInclusionBaseCondition($query));
    $query->condition("{$embargo_alias}.embargo_type", $embargo_types, 'IN');

    return $query;
  }

  /**
   * Get query for positive assertion.
   *
   * @param array $target_aliases
   *   The target aliases on which to match.
   * @param array $embargo_types
   *   The relevant types of embargoes to which to constrain.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The positive-asserting query.
   */
  protected function getAccessibleEmbargoesQuery(array $target_aliases, array $embargo_types) : SelectInterface {
    // Embargo exists for the entity, where:
    $embargo_alias = 'embargo_existence';
    $embargo_existence = $this->database->select('embargo', $embargo_alias);
    $embargo_existence->addExpression(1, 'embargo_allowed');

    $embargo_existence->addMetaData('embargo_alias', $embargo_alias);
    $embargo_existence->addMetaData('embargo_target_aliases', $target_aliases);

    $embargo_existence->condition(
      $embargo_existence->orConditionGroup()
        ->condition($existence_condition = $embargo_existence->andConditionGroup()
          ->condition($this->buildInclusionBaseCondition($embargo_existence))
          ->condition($embargo_or = $embargo_existence->orConditionGroup())
      )
    );

    $embargo_existence->addMetaData('embargo_existence_condition', $existence_condition);

    // - The request IP is exempt.
    /** @var \Drupal\embargo\IpRangeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('embargo_ip_range');
    $applicable_ip_ranges = $storage->getApplicableIpRanges($this->currentIp);
    if ($applicable_ip_ranges) {
      $embargo_or->condition("{$embargo_alias}.exempt_ips", array_keys($applicable_ip_ranges), 'IN');
    }

    // - The user is exempt.
    // @todo Should the IP range constraint(s) take precedence?
    $user_existence = $this->database->select('embargo__exempt_users', 'eeu');
    $user_existence->addExpression(1, 'user_existence');
    $user_existence->where("eeu.entity_id = {$embargo_alias}.id")
      ->condition('eeu.exempt_users_target_id', $this->user->id());
    $embargo_or->exists($user_existence);

    // - There's a scheduled embargo of an appropriate type and no other
    //   overriding embargo.
    $current_date = $this->dateFormatter->format($this->time->getRequestTime(), 'custom', DateTimeItemInterface::DATE_STORAGE_FORMAT);
    // No indefinite embargoes or embargoes expiring in the future.
    $unexpired_embargo_subquery = $this->database->select('embargo', 'ue')
      ->addMetaData('embargo_alias', $embargo_alias)
      ->addMetaData('embargo_target_aliases', $target_aliases)
      ->addMetaData('embargo_unexpired_alias', 'ue');
    $unexpired_embargo_subquery->condition($this->buildExclusionBaseCondition($unexpired_embargo_subquery))
      ->condition('ue.embargo_type', $embargo_types, 'IN');
    $unexpired_embargo_subquery->addExpression(1, 'ueee');
    $unexpired_embargo_subquery->condition($unexpired_embargo_subquery->orConditionGroup()
      ->condition('ue.expiration_type', EmbargoInterface::EXPIRATION_TYPE_INDEFINITE)
      ->condition($unexpired_embargo_subquery->andConditionGroup()
        ->condition('ue.expiration_type', EmbargoInterface::EXPIRATION_TYPE_SCHEDULED)
        ->condition('ue.expiration_date', $current_date, '>')
      )
    );

    $embargo_or->condition(
      $embargo_or->andConditionGroup()
        ->condition("{$embargo_alias}.embargo_type", $embargo_types, 'IN')
        ->condition("{$embargo_alias}.expiration_type", EmbargoInterface::EXPIRATION_TYPE_SCHEDULED)
        ->condition("{$embargo_alias}.expiration_date", $current_date, '<=')
        ->notExists($unexpired_embargo_subquery)
    );

    return $embargo_existence;
  }

}
