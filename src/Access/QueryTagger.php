<?php

namespace Drupal\embargo\Access;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\embargo\EmbargoInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles tagging entity queries with access restrictions for embargoes.
 */
class QueryTagger {

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
   */
  public function __construct(AccountProxyInterface $user, RequestStack $request_stack, Connection $database, EntityTypeManagerInterface $entity_type_manager) {
    $this->user = $user;
    $this->currentIp = $request_stack->getCurrentRequest()->getClientIp();
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Ensure the given query represents an "AND" to which we can attach filters.
   *
   * Queries can select either "OR" or "AND" as their base conjunction when they
   * are created; however, constraining results is much easier with "AND"... so
   * let's rework the query object to make it so.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query with which to deal.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The query which has been dealt with... should be the same query, just
   *   returning for (potential) convenience.
   */
  protected function andifyQuery(SelectInterface $query) {
    $original_conditions =& $query->conditions();
    if ($original_conditions['#conjunction'] === 'AND') {
      // Nothing to do...
      return $query;
    }

    $new_or = $query->orConditionGroup();

    $original_conditions_copy = $original_conditions;
    unset($original_conditions_copy['#conjunction']);
    foreach ($original_conditions_copy as $orig_cond) {
      $new_or->condition($orig_cond['field'], $orig_cond['value'] ?? NULL, $orig_cond['operator'] ?? '=');
    }

    $new_and = $query->andConditionGroup()
      ->condition($new_or);

    $original_conditions = $new_and->conditions();

    return $query;
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
    if (!in_array($type, ['node', 'media', 'file'])) {
      throw new \InvalidArgumentException("Unrecognized type '$type'.");
    }
    elseif ($this->user->hasPermission('bypass embargo access')) {
      return;
    }
    $this->andifyQuery($query);

    /** @var \Drupal\core\Entity\Sql\SqlEntityStorageInterface $storage */
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
          $to_apply->condition("{$alias}.{$key}", $this->buildInaccessibleMediaCondition(), 'NOT IN');
        }
        elseif ($type === 'file') {
          $to_apply->condition("{$alias}.{$key}", $this->buildInaccessibleFileCondition(), 'NOT IN');
        }
        else {
          throw new \InvalidArgumentException("Invalid type '$type'.");
        }
      }
    }
  }

  /**
   * Builds the condition for files that are inaccessible.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The sub-query to be used that results in all file IDs that cannot be
   *   accessed.
   */
  protected function buildInaccessibleFileCondition() {
    $file_query = $this->database->select('file_managed', 'f')->fields('f', ['fid']);
    /** @var \Drupal\field\Entity\FieldStorageConfig[] $fields */
    $fields = $this->entityTypeManager->getStorage('field_storage_config')->loadMultiple();
    $file_fields_or = $file_query->orConditionGroup();
    foreach ($fields as $field) {
      $settings = $field->get('settings');
      if ($field->get('entity_type') === 'media' && isset($settings['target_type']) && $settings['target_type'] === 'file') {
        $field_name = $field->get('field_name');
        $target_field = "{$field_name}_target_id";
        $alias = $file_query->leftJoin("media__{$field_name}", $field_name, "f.fid = %alias.$target_field");
        $file_fields_or->condition("{$alias}.entity_id", $this->buildInaccessibleMediaCondition(), 'IN');
      }
    }
    $file_query->condition($file_fields_or);
    return $file_query;

  }

  /**
   * Builds the condition for media that are inaccessible.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The sub-query to be used that results in all media IDs that cannot be
   *   accessed.
   */
  protected function buildInaccessibleMediaCondition() {
    $media_query = $this->database->select('media', 'm');
    $alias = $media_query->leftJoin('media__field_media_of', 'f', 'm.mid = %alias.entity_id');
    return $media_query->condition("$alias.field_media_of_target_id", $this->buildInaccessibleEmbargoesCondition(), 'IN')
      ->fields('m', ['mid']);
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

    /** @var \Drupal\embargo\IpRangeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('embargo_ip_range');
    $applicable_ip_ranges = $storage->getApplicableIpRanges($this->currentIp);
    if (!empty($applicable_ip_ranges)) {
      $group->condition('e.exempt_ips', array_keys($applicable_ip_ranges), 'IN');
    }
    $group->condition('e.embargo_type', EmbargoInterface::EMBARGO_TYPE_FILE);
    $alias = $accessible_embargoes->leftJoin('embargo__exempt_users', 'u', 'e.id = %alias.entity_id');
    $group->condition("$alias.exempt_users_target_id", $this->user->id());
    $accessible_embargoes->fields('e', ['embargoed_node'])->condition($group);

    $inaccessible_nids = $this->database->select('embargo', 'ein');
    return $inaccessible_nids->condition('ein.embargoed_node', $accessible_embargoes, 'NOT IN')
      ->fields('ein', ['embargoed_node']);
  }

}
