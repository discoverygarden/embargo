<?php

namespace Drupal\embargo;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;

/**
 * Track embargo items.
 */
class EmbargoItemList extends EntityReferenceFieldItemList {

  use ComputedItemListTrait;

  /**
   * {@inheritDoc}
   */
  protected function computeValue() {
    $entity = $this->getEntity();
    /** @var \Drupal\embargo\EmbargoStorageInterface $embargo_storage */
    $embargo_storage = $this->getEntityTypeManager()->getStorage('embargo');
    $this->setValue(array_filter($embargo_storage->getApplicableEmbargoes($entity), function (EmbargoInterface $embargo) {
      return in_array($embargo->getEmbargoType(), $this->getSetting('embargo_types'));
    }));
  }

  /**
   * Helper; get the entity type manager service.
   *
   * XXX: Dependency injection does not presently appear to be possible in typed
   * data.
   *
   * @see https://www.drupal.org/node/2053415
   * @see https://www.drupal.org/project/drupal/issues/3294266
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager service.
   */
  protected function getEntityTypeManager() : EntityTypeManagerInterface {
    return \Drupal::entityTypeManager();
  }

}
