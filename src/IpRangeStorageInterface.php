<?php

namespace Drupal\embargo;

use Drupal\Core\Entity\ContentEntityStorageInterface;

/**
 * Interface for Embargoes' IP Range entities.
 */
interface IpRangeStorageInterface extends ContentEntityStorageInterface {

  /**
   * Helper to retrieve all IP Range entities given a IP address.
   *
   * @param string $ip
   *   The IP address to check.
   *
   * @return \Drupal\embargo\IpRangeInterface[]
   *   An array of IpRangeInterface objects that
   *   the given IP falls within, indexed by their IDs.
   */
  public function getApplicableIpRanges(string $ip): array;

}
