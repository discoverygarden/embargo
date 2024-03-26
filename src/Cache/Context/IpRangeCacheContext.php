<?php

namespace Drupal\embargo\Cache\Context;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Applicable embargo IP range cache context info.
 */
class IpRangeCacheContext implements CacheContextInterface {

  /**
   * Memoized ranges.
   *
   * @var \Drupal\embargo\IpRangeInterface[]
   */
  protected array $ranges;

  /**
   * Constructor.
   */
  public function __construct(
    protected RequestStack $requestStack,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    // No-op, other than stashing properties.
  }

  /**
   * {@inheritDoc}
   */
  public static function getLabel() {
    return \t('Embargo, Applicable IP Ranges');
  }

  /**
   * {@inheritDoc}
   */
  public function getContext() {
    $range_keys = array_keys($this->getRanges());
    sort($range_keys, SORT_NUMERIC);
    return implode(',', $range_keys);
  }

  /**
   * {@inheritDoc}
   */
  public function getCacheableMetadata() {
    $cache_meta = new CacheableMetadata();

    foreach ($this->getRanges() as $range) {
      $cache_meta->addCacheableDependency($range);
    }

    return $cache_meta;
  }

  /**
   * Get any IP range entities associated with the current IP address.
   *
   * @return \Drupal\embargo\IpRangeInterface[]
   *   Any relevant IP range entities.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getRanges() : array {
    if (!isset($this->ranges)) {
      /** @var \Drupal\embargo\IpRangeStorageInterface $embargo_ip_range_storage */
      $embargo_ip_range_storage = $this->entityTypeManager->getStorage('embargo_ip_range');
      $this->ranges = $embargo_ip_range_storage->getApplicableIpRanges($this->requestStack->getCurrentRequest()
        ->getClientIp());
    }

    return $this->ranges;
  }

}
