<?php

namespace Drupal\embargo\Access;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Determine whether an entity is embargoed and should be accessible.
 */
interface EmbargoAccessCheckInterface {

  /**
   * Checks if access to the given entity is restricted by an embargo.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The currently logged in account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(EntityInterface $entity, AccountInterface $user);

}
