<?php

namespace Drupal\embargo;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\islandora_hierarchical_access\LUTGeneratorInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base implementation of methods for the interface.
 *
 * @see \Drupal\embargo\EmbargoStorageInterface
 */
trait EmbargoStorageTrait {

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request|null
   */
  protected ?Request $request;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $user;

  /**
   * {@inheritdoc}
   */
  public static function applicableEntityTypes(): array {
    return EmbargoStorageInterface::APPLICABLE_ENTITY_TYPES;
  }

  /**
   * {@inheritdoc}
   */
  public function getApplicableEmbargoes(EntityInterface $entity): array {
    assert($this instanceof SqlContentEntityStorage);

    if ($entity instanceof NodeInterface) {
      $properties = ['embargoed_node' => $entity->id()];
      return $this->loadByProperties($properties);
    }
    elseif ($entity instanceof MediaInterface || $entity instanceof FileInterface) {
      $query = $this->database->select('embargo', 'e')
        ->fields('e', ['id'])
        ->distinct();
      $lut_alias = $query->join(LUTGeneratorInterface::TABLE_NAME, 'lut', '%alias.nid = e.embargoed_node');
      $key = $entity instanceof MediaInterface ? 'mid' : 'fid';
      $query->condition("{$lut_alias}.{$key}", $entity->id());
      $ids = $query->execute()->fetchCol();
      return $this->loadMultiple($ids);
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getApplicableNonExemptNonExpiredEmbargoes(EntityInterface $entity, ?int $timestamp = NULL, ?AccountInterface $user = NULL, ?string $ip = NULL): array {
    $timestamp = $timestamp ?? $this->request->server->get('REQUEST_TIME');
    $user = $user ?? $this->user;
    $ip = $ip ?? $this->request->getClientIp();
    return array_filter($this->getApplicableEmbargoes($entity), function ($embargo) use ($entity, $timestamp, $user, $ip): bool {
      $inactive = $embargo->expiresBefore($timestamp);
      $type_exempt = ($entity instanceof NodeInterface && $embargo->getEmbargoType() !== EmbargoInterface::EMBARGO_TYPE_NODE);
      $user_exempt = $embargo->isUserExempt($user);
      $ip_exempt = $embargo->ipIsExempt($ip);
      return !($inactive || $type_exempt || $user_exempt || $ip_exempt);
    });
  }

  /**
   * Set the user visible to the trait.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user with which to evaluate.
   *
   * @return \Drupal\embargo\EmbargoStorageInterface|\Drupal\embargo\EmbargoStorageTrait
   *   Fluent interface; the current object.
   */
  protected function setUser(AccountInterface $user) : self {
    $this->user = $user;
    return $this;
  }

  /**
   * The request visible to the trait.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The request with which to evaluate.
   *
   * @return \Drupal\embargo\EmbargoStorageInterface|\Drupal\embargo\EmbargoStorageTrait
   *   Fluent interface; the current object.
   */
  protected function setRequest(?Request $request) : self {
    $this->request = $request;
    return $this;
  }

}
