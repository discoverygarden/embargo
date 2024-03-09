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
   * Helper; apply existence checks to a node(-like) table.
   *
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

  protected function getNullQuery(array $target_aliases, array $embargo_types) : SelectInterface {
    $embargo_alias = 'embargo_null';
    $query = $this->database->select('embargo', $embargo_alias);
    $query->addExpression(1, 'embargo_null_e');

    $query->where(strtr('!field IN (!targets)', [
      '!field' => "{$embargo_alias}.embargoed_node",
      '!targets' => implode(', ', $target_aliases),
    ]));
    $query->condition("{$embargo_alias}.embargo_type", $embargo_types, 'IN');

    return $query;
  }

  protected function getAccessibleEmbargoesQuery(array $target_aliases, array $embargo_types) : SelectInterface {
    // Embargo exists for the entity, where:
    $embargo_alias = 'embargo_existence';
    $embargo_existence = $this->database->select('embargo', $embargo_alias);
    $embargo_existence->addExpression(1, 'embargo_allowed');

    $embargo_existence->addMetaData('embargo_alias', $embargo_alias);

    $replacements = [
      '!field' => "{$embargo_alias}.embargoed_node",
      '!targets' => implode(', ', $target_aliases),
    ];
    $embargo_existence->condition(
      $embargo_existence->orConditionGroup()
        ->condition($existence_condition = $embargo_existence->andConditionGroup()
          ->where(strtr('!field IN (!targets)', $replacements))
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
      ->where("ue.embargoed_node = {$embargo_alias}.embargoed_node")
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
