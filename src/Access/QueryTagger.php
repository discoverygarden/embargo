<?php

namespace Drupal\embargo\Access;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\embargo\EmbargoInterface;
use Drupal\islandora_hierarchical_access\Access\QueryConjunctionTrait;
use Drupal\islandora_hierarchical_access\LUTGeneratorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles tagging entity queries with access restrictions for embargoes.
 */
class QueryTagger {

  use QueryConjunctionTrait;

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
  protected string $currentIp;

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
      // Being run as a subquery, we do not want to add again.
      return;
    }
    if ($this->user->hasPermission('bypass embargo access')) {
      return;
    }
    $type = 'node';

    static::conjunctionQuery($query);

    /** @var \Drupal\Core\Entity\Sql\SqlEntityStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage($type);
    $tables = $storage->getTableMapping()->getTableNames();

    $target_aliases = [];

    $tagged_table_aliases = $query->getMetaData('embargo_tagged_table_aliases') ?? [];

    foreach ($query->getTables() as $info) {
      if ($info['table'] instanceof SelectInterface) {
        continue;
      }
      elseif (in_array($info['table'], $tables)) {
        $key = (str_starts_with($info['table'], "{$type}__")) ? 'entity_id' : (substr($type, 0, 1) . "id");
        $alias = $info['alias'];
        if (!in_array($alias, $tagged_table_aliases)) {
          $tagged_table_aliases[] = $alias;
          $target_aliases[] = "{$alias}.{$key}";
        }
      }
    }

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

      $embargo_alias = $existence_query->leftJoin('embargo', 'e', '%alias.embargoed_node = existence_node.nid');
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
      $embargo_and->condition("{$embargo_alias}.embargo_type", EmbargoInterface::EMBARGO_TYPE_NODE);

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

  /**
   * Get query to select accessible embargoed entities.
   *
   * @param int $type
   *   The type of embargo, expected to be one of:
   *   - EmbargoInterface::EMBARGO_TYPE_NODE; or,
   *   - EmbargoInterface::EMBARGO_TYPE_FILE.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   A query returning things that should not be inaccessible.
   */
  protected function buildAccessibleEmbargoesQuery(int $type) : SelectInterface {
    $query = $this->database->select('embargo', 'e')
      ->fields('e', ['embargoed_node']);

    // Things are visible if...
    $group = $query->orConditionGroup()
      // The selected embargo entity does not apply to the given type; or...
      ->condition('e.embargo_type', $type, '!=');

    $group->condition($query->andConditionGroup()
      // ... a scheduled embargo...
      ->condition('e.expiration_type', EmbargoInterface::EXPIRATION_TYPE_SCHEDULED)
      // ... has a date in the past.
      ->condition('e.expiration_date', $this->dateFormatter->format($this->time->getRequestTime(), 'custom', DateTimeItemInterface::DATE_STORAGE_FORMAT), '<')
    );

    // ... the incoming IP is in an exempt range; or...
    /** @var \Drupal\embargo\IpRangeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('embargo_ip_range');
    $applicable_ip_ranges = $storage->getApplicableIpRanges($this->currentIp);
    if (!empty($applicable_ip_ranges)) {
      $group->condition('e.exempt_ips', array_keys($applicable_ip_ranges), 'IN');
    }

    // ... the specific user is exempted from the embargo.
    $user_alias = $query->leftJoin('embargo__exempt_users', 'u', 'e.id = %alias.entity_id');
    $group->condition("{$user_alias}.exempt_users_target_id", $this->user->id());

    $query->condition($group);

    return $query;
  }

}
