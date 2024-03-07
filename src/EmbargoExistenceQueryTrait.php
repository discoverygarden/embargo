<?php

namespace Drupal\embargo;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;

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
   * @param \Drupal\Core\Database\Query\SelectInterface $existence_query
   *   The query to which to add.
   * @param string $target_alias
   *   The alias of the node-like table in the query to which to attach things.
   * @param array $embargo_types
   *   The types of embargo to deal with.
   */
  protected function applyExistenceQuery(SelectInterface $existence_query, string $target_alias, array $embargo_types) {
    $embargo_alias = $existence_query->leftJoin('embargo', 'e', "%alias.embargoed_node = {$target_alias}.nid");
    $user_alias = $existence_query->leftJoin('embargo__exempt_users', 'u', "%alias.entity_id = {$embargo_alias}.id");
    $existence_or = $existence_query->orConditionGroup();

    // No embargo.
    // XXX: Might have to change to examine one of the fields outside the join
    // condition?
    $existence_or->isNull("{$embargo_alias}.embargoed_node");

    // The user is exempt from the embargo.
    $existence_or->condition("{$user_alias}.exempt_users_target_id", $this->user->id());

    // ... the incoming IP is in an exempt range; or...
    /** @var \Drupal\embargo\IpRangeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('embargo_ip_range');
    $applicable_ip_ranges = $storage->getApplicableIpRanges($this->currentIp);
    if (!empty($applicable_ip_ranges)) {
      $existence_or->condition("{$embargo_alias}.exempt_ips", array_keys($applicable_ip_ranges), 'IN');
    }

    // With embargo, without exemption.
    $embargo_and = $existence_or->andConditionGroup();

    // Has an embargo of a relevant type.
    $embargo_and->condition("{$embargo_alias}.embargo_type", $embargo_types, 'IN');

    $current_date = $this->dateFormatter->format($this->time->getRequestTime(), 'custom', DateTimeItemInterface::DATE_STORAGE_FORMAT);
    // No indefinite embargoes or embargoes expiring in the future.
    $unexpired_embargo_subquery = $this->database->select('embargo', 'ue')
      ->fields('ue', ['embargoed_node']);
    $unexpired_embargo_subquery->condition($unexpired_embargo_subquery->orConditionGroup()
      ->condition('ue.expiration_type', EmbargoInterface::EXPIRATION_TYPE_INDEFINITE)
      ->condition($unexpired_embargo_subquery->andConditionGroup()
        ->condition('ue.expiration_type', EmbargoInterface::EXPIRATION_TYPE_SCHEDULED)
        ->condition('ue.expiration_date', $current_date, '>')
      )
    );
    $embargo_and
      ->condition(
        "{$embargo_alias}.embargoed_node",
        $unexpired_embargo_subquery,
        'NOT IN',
      )
      ->condition("{$embargo_alias}.expiration_type", EmbargoInterface::EXPIRATION_TYPE_SCHEDULED)
      ->condition("{$embargo_alias}.expiration_date", $current_date, '<=');

    $existence_or->condition($embargo_and);
    $existence_query->condition($existence_or);
  }

}
