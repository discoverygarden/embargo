<?php

namespace Drupal\embargo;

use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Interface for the service for interacting with Embargoes embargo entities.
 */
interface EmbargoStorageInterface extends ContentEntityStorageInterface {

  const APPLICABLE_ENTITY_TYPES = [
    'node',
    'media',
    'file',
  ];

  /**
   * A list of entity types which an embargo can apply to.
   *
   * @return string[]
   *   A list of entity types identifiers which an embargo can apply to.
   *
   * @obsolete
   */
  public static function applicableEntityTypes();

  /**
   * Get all applicable embargoes for the given entity.
   *
   * All applicable embargoes are returned regardless of
   * exemption or expiration status.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to which the embargo applies.
   *
   * @return EmbargoInterface[]
   *   A list of embargo entities that apply to the given entity.
   */
  public function getApplicableEmbargoes(EntityInterface $entity): array;

  /**
   * Get non-exempt active embargoes for the given entity, user and IP address.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to which the embargo applies.
   * @param int|null $timestamp
   *   A UNIX timestamp for which embargoes are considered active if their
   *   expiration date exceeds the timestamp.
   *   If not given the current request time is used.
   * @param \Drupal\Core\Session\AccountInterface|null $user
   *   The user for which exemptions might apply.
   *   If not given the current logged in user is used.
   * @param string|null $ip
   *   The IP address for which exemptions might apply.
   *   If not given the IP of the current request is used.
   *
   * @return EmbargoInterface[]
   *   A list of embargo entities that apply to the given entity,
   *   which are not exempt or expired for the given arguments.
   */
  public function getApplicableNonExemptNonExpiredEmbargoes(EntityInterface $entity, ?int $timestamp = NULL, ?AccountInterface $user = NULL, ?string $ip = NULL): array;

}
