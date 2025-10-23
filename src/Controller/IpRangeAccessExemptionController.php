<?php

namespace Drupal\embargo\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for displaying an IP access denied message.
 */
class IpRangeAccessExemptionController extends ControllerBase {

  /**
   * Formats a response for an IP access denied page.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request being served.
   *
   * @return array
   *   Renderable array of markup for IP access denied.
   */
  public function response(Request $request) : array {
    $ranges = [];
    $cache_tags = [];
    /** @var \Drupal\embargo\IpRangeInterface[] $entities */
    $entities = $this->entityTypeManager()->getStorage('embargo_ip_range')->loadMultiple($request->query->all()['ranges'] ?? []);
    foreach ($entities as $entity) {
      $ranges[] = [
        'label' => $entity->label(),
        'proxy_url' => $entity->getProxyUrl(),
      ];
      $cache_tags = Cache::mergeTags($cache_tags, $entity->getCacheTags());
    }

    return [
      '#theme' => 'embargo_ip_access_exemption',
      '#resources' => $request->query->all()['resources'] ?? [],
      '#ranges' => $ranges,
      '#contact_email' => $this->config('embargo.settings')->get('contact_email'),
      '#cache' => [
        'contexts' => [
          'user',
          'url.path',
          'url.query_args',
        ],
        'tags' => $cache_tags,
      ],
    ];
  }

}
