<?php

namespace Drupal\embargo\PageCache;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\PageCache\ResponsePolicyInterface;
use Drupal\Core\Routing\AccessAwareRouterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Avoid page_cache when IP embargoes are in play.
 *
 * IP embargoes break the assumption made by page_cache that all anonymous
 * requests are equivalent, so let's avoid allowing things to go to the
 * page_cache for anonymous users when an access result has the
 * `ip.embargo_range` (or wider `ip`) context.
 */
class DenyIpDependentResponse implements ResponsePolicyInterface {

  /**
   * Cache contexts of which the presence should suppress page_cache.
   */
  protected const IP_CONTEXTS = [
    'ip.embargo_range',
    // XXX: `ip.embargo_range` could be optimized away if the `ip` context
    // itself is added, so let's also account for it.
    'ip',
  ];

  /**
   * {@inheritDoc}
   */
  public function check(Response $response, Request $request) : ?string {
    if (!$request->attributes->has(AccessAwareRouterInterface::ACCESS_RESULT)) {
      // No access result; unable to check.
      return NULL;
    }

    /** @var \Drupal\Core\Access\AccessResultInterface $access_result */
    $access_result = $request->attributes->get(AccessAwareRouterInterface::ACCESS_RESULT);

    if (!($access_result instanceof RefinableCacheableDependencyInterface)) {
      // Access result is not cacheable; unable to check cache contexts.
      return NULL;
    }

    $cache_contexts = $access_result->getCacheContexts();

    if (array_intersect(static::IP_CONTEXTS, $cache_contexts)) {
      // Access result has relevant context; avoiding page cache.
      return ResponsePolicyInterface::DENY;
    }

    // No candidate IP cache contexts present on access result; passing.
    return NULL;
  }

}
