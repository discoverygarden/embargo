<?php

namespace Drupal\embargo;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Storage for embargo_ip_range entities.
 */
class IpRangeStorage extends SqlContentEntityStorage implements IpRangeStorageInterface {

  /**
   * {@inheritdoc}
   */
  public function getApplicableIpRanges($ip): array {
    /** @var \Drupal\embargo\IpRangeInterface[] $ranges */
    $ranges = $this->loadMultiple();
    return array_filter($ranges, function (IpRangeInterface $range) use ($ip) {
      return $range->withinRanges($ip);
    });
  }

}
