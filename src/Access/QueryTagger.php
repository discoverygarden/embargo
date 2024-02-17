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
  public function tagAccess(SelectInterface $query, string $type) : void {
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

    $target_aliases = [];

    foreach ($query->getTables() as $info) {
      if ($info['table'] instanceof SelectInterface) {
        continue;
      }
      elseif (in_array($info['table'], $tables)) {
        $key = (str_starts_with($info['table'], "{$type}__")) ? 'entity_id' : (substr($type, 0, 1) . "id");
        $alias = $info['alias'];
        $target_aliases[] = "{$alias}.{$key}";
      }
    }

    if (empty($target_aliases)) {
      return;
    }

    $existence = $this->database->select('node', 'existence_node');
    $existence->fields('existence_node', ['nid']);

    if ($type !== 'node') {
      $lut_column = match($type) {
        'file' => 'fid',
        'media' => 'mid',
      };
      $existence_lut_alias = $existence->leftJoin(LUTGeneratorInterface::TABLE_NAME, 'lut', '%alias.nid = existence_node.nid');
      $existence->where(strtr('!field IS NULL OR !field IN (!targets)', [
        '!field' => "{$existence_lut_alias}.{$lut_column}",
        '!targets' => implode(', ', $target_aliases),
      ]));
    }
    else {
      $existence->where(strtr('!field IN (!targets)', [
        '!field' => 'existence_node.nid',
        '!targets' => implode(', ', $target_aliases),
      ]));
    }

    $exist_or = $existence->orConditionGroup();

    // No embargo.
    $embargo = $this->database->select('embargo', 'ee');
    $embargo->fields('ee', ['embargoed_node']);
    $exist_or->condition("existence_node.nid", $embargo, 'NOT IN');

    // Embargoed (and allowed).
    $accessible_embargoes = $this->buildAccessibleEmbargoesQuery(match($type) {
      'file', 'media' => EmbargoInterface::EMBARGO_TYPE_FILE,
      'node' => EmbargoInterface::EMBARGO_TYPE_NODE,
    });
    $exist_or->condition("existence_node.nid", $accessible_embargoes, 'IN');

    $existence->condition($exist_or);

    $query->exists($existence);
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
