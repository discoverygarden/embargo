<?php

namespace Drupal\embargo\Access;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\embargo\EmbargoInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\islandora_hierarchical_access\Access\QueryTagger as HierarchicalQueryTagger;

/**
 * Handles tagging entity queries with access restrictions for embargoes.
 */
class QueryTagger extends HierarchicalQueryTagger {

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
   * Service that gets used to tag entity queries.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $user
   *   The current user.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The current request stack used to get the client's IP.
   * @param \Drupal\Core\Database\Connection $database
   *   The Drupal database service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   Module handler interface.
   */
  public function __construct(
    AccountProxyInterface $user,
    RequestStack $request_stack,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $moduleHandler
  ) {
    parent::__construct($database, $moduleHandler, $entity_type_manager);
    $this->user = $user;
    $this->currentIp = $request_stack->getCurrentRequest()->getClientIp();
  }

  /**
   * Builds up the conditions used to restrict media or nodes.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query being executed.
   * @param string $type
   *   Either "node", "media" or "file".
   */
  public function tagAccess(SelectInterface $query, string $type) {
    if (!in_array($type, ['node', 'file', 'media'])) {
      throw new \InvalidArgumentException("Unrecognized type '$type'.");
    }
    elseif ($this->user->hasPermission('bypass embargo access')) {
      return;
    }
    $this->andifyQuery($query);

    /**
     * @var \Drupal\Core\Entity\Sql\SqlEntityStorageInterface $storage
     */
    $storage = $this->entityTypeManager->getStorage($type);
    $tables = $storage->getTableMapping()->getTableNames();

    foreach ($query->getTables() as $info) {
      if ($info['table'] instanceof SelectInterface) {
        continue;
      }
      elseif (in_array($info['table'], $tables)) {
        $key = (strpos($info['table'],
            "{$type}__") === 0) ? 'entity_id' : (substr($type, 0, 1) . "id");
        $alias = $info['alias'];

        $to_apply = $query;
        if ($info['join type'] == 'LEFT') {
          $to_apply = $query->orConditionGroup()
            ->isNull("{$alias}.{$key}");
          $query->condition($to_apply);
        }
        if ($type === 'node') {
          $to_apply->condition("{$alias}.{$key}",
            $this->buildInaccessibleEmbargoesCondition(), 'NOT IN');
        }
        elseif ($type === 'media') {
          $to_apply->condition("{$alias}.{$key}", $this->buildInaccessibleMediaCondition('mid'), 'NOT IN');
        }
        elseif ($type === 'file') {
          $to_apply->condition("{$alias}.{$key}", $this->buildInaccessibleMediaCondition('fid'), 'NOT IN');
        }
        else {
          throw new \InvalidArgumentException("Invalid type '$type'.");
        }
      }
    }
  }

  /**
   * Builds the condition for media that are inaccessible.
   *
   * @param string $field
   *   The field to get.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The sub-query to be used that results in all media IDs that cannot be
   *   accessed.
   */
  protected function buildInaccessibleMediaCondition($field = 'fid') {
    $query = $this->getBaseMediaQuery(FALSE, $field);
    return $query->condition("lut.nid", $this->buildInaccessibleEmbargoesCondition(), 'IN');
  }

  /**
   * Builds the condition for embargoes that are inaccessible.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The sub-query to be used that results in embargoed_node IDs that
   *   cannot be accessed.
   */
  protected function buildInaccessibleEmbargoesCondition() {
    $accessible_embargoes = $this->database->select('embargo', 'e');
    $group = $accessible_embargoes->orConditionGroup();

    /**
     * @var \Drupal\embargo\IpRangeStorageInterface $storage
     */
    $storage = $this->entityTypeManager->getStorage('embargo_ip_range');
    $applicable_ip_ranges = $storage->getApplicableIpRanges($this->currentIp);
    if (!empty($applicable_ip_ranges)) {
      $group->condition('e.exempt_ips', array_keys($applicable_ip_ranges),
        'IN');
    }
    $group->condition('e.embargo_type', EmbargoInterface::EMBARGO_TYPE_FILE);
    $alias = $accessible_embargoes->leftJoin('embargo__exempt_users', 'u',
      'e.id = %alias.entity_id');
    $group->condition("$alias.exempt_users_target_id", $this->user->id());
    $accessible_embargoes->fields('e', ['embargoed_node'])->condition($group);

    $inaccessible_nids = $this->database->select('embargo', 'ein');
    return $inaccessible_nids->condition('ein.embargoed_node',
      $accessible_embargoes, 'NOT IN')
      ->fields('ein', ['embargoed_node']);
  }

}
