<?php

namespace Drupal\embargo\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for displaying an IP access denied message.
 */
class IpRangeAccessExemptionController extends ControllerBase {

  /**
   * The HTTP request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Constructs an IP access denied controller.
   *
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request.
   */
  public function __construct(Request $request = NULL) {
    $this->request = $request;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack')->getCurrentRequest());
  }

  /**
   * Formats a response for an IP access denied page.
   *
   * @return array
   *   Renderable array of markup for IP access denied.
   */
  public function response() {
    $ranges = [];
    $cache_tags = [];
    $resources = (array) $this->request->query->get('resources', []);
    /** @var \Drupal\embargo\IpRangeInterface[] $entities */
    $entities = $this->entityTypeManager()->getStorage('embargo_ip_range')->loadMultiple((array) $this->request->query->get('ranges', []));
    foreach ($entities as $entity) {
      $ranges[] = [
        'label' => $entity->label(),
        'proxy_url' => $entity->getProxyUrl(),
      ];
      $cache_tags = Cache::mergeTags($cache_tags, $entity->getCacheTags());
    }

    return [
      '#theme' => 'embargo_ip_access_exemption',
      '#resources' => $resources,
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
