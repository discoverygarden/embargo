<?php

namespace Drupal\embargo;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Storage for embargo entities.
 */
class EmbargoStorage extends SqlContentEntityStorage implements EmbargoStorageInterface {

  use EmbargoStorageTrait;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return parent::createInstance($container, $entity_type)
      ->setRequest($container->get('request_stack')->getCurrentRequest())
      ->setUser($container->get('current_user'));
  }

}
