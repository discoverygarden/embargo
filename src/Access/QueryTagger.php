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
  protected $user;

  /**
   * The IP of the request.
   *
   * @var string
   */
  protected $currentIp;

  /**
   * Instance of a Drupal database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * @param string $type
   *   Either "node" or "file".
   */
  public function tagAccess(SelectInterface $query, string $type) {
    if (!in_array($type, ['node', 'media', 'file'])) {
      throw new \InvalidArgumentException("Unrecognized type '$type'.");
    }
    elseif ($this->user->hasPermission('bypass embargo access')) {
      return;
    }

    static::conjunctionQuery($query);

    /** @var \Drupal\Core\Entity\Sql\SqlEntityStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage($type);
    $tables = $storage->getTableMapping()->getTableNames();

    foreach ($query->getTables() as $info) {
      if ($info['table'] instanceof SelectInterface) {
        continue;
      }
      elseif (in_array($info['table'], $tables)) {
        $key = (strpos($info['table'], "{$type}__") === 0) ? 'entity_id' : (substr($type, 0, 1) . "id");
        $alias = $info['alias'];

        $to_apply = $query;
        if ($info['join type'] == 'LEFT') {
          $to_apply = $query->orConditionGroup()
            ->isNull("{$alias}.{$key}");
          $query->condition($to_apply);
        }
        if ($type === 'node') {
          $to_apply->condition("{$alias}.{$key}", $this->buildInaccessibleEmbargoesCondition(), 'NOT IN');
        }
        elseif ($type === 'media') {
          $to_apply->condition("{$alias}.{$key}", $this->buildInaccessibleFileCondition('mid'), 'NOT IN');
        }
        elseif ($type === 'file') {
          $to_apply->condition("{$alias}.{$key}", $this->buildInaccessibleFileCondition('fid'), 'NOT IN');
        }
        else {
          throw new \InvalidArgumentException("Invalid type '$type'.");
        }
      }
    }
  }

  /**
   * Builds the condition for file-typed embargoes that are inaccessible.
   *
   * @param string $lut_column
   *   The particular column of the LUT to return, as file embargoes apply to
   *   media ('mid') as well as files ('fid').
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The sub-query to be used that results in all file IDs that cannot be
   *   accessed.
   */
  protected function buildInaccessibleFileCondition(string $lut_column) {
    $query = $this->database->select('embargo', 'e');
    $lut_alias = $query->join(LUTGeneratorInterface::TABLE_NAME, 'lut', '%alias.nid = e.embargoed_node');
    return $query
      ->fields($lut_alias, [$lut_column])
      ->condition('lut.nid', $this->buildAccessibleEmbargoesQuery(EmbargoInterface::EMBARGO_TYPE_FILE), 'NOT IN');
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
  protected function buildAccessibleEmbargoesQuery($type) : SelectInterface {
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

    // ... the user has a role that is exempted from the embargo.
    $role_alias = $query->leftJoin('embargo__exempt_roles', 'r', 'e.id = %alias.entity_id');
    $group->condition("{$role_alias}.exempt_roles_target_id", $this->user->getRoles(), 'IN');

    $query->condition($group);

    return $query;
  }

  /**
   * Builds the condition for embargoes that are inaccessible.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The sub-query to be used that results in embargoed_node IDs that
   *   cannot be accessed.
   */
  protected function buildInaccessibleEmbargoesCondition() : SelectInterface {
    return $this->database->select('embargo', 'ein')
      ->condition('ein.embargoed_node', $this->buildAccessibleEmbargoesQuery(EmbargoInterface::EMBARGO_TYPE_NODE), 'NOT IN')
      ->fields('ein', ['embargoed_node']);
  }

}
