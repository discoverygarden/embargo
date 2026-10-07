<?php

namespace Drupal\embargo\Plugin\Menu;

use Drupal\Core\Menu\LocalActionDefault;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;

/**
 * Defines a local action to add an embargo to the current node.
 */
class EmbargoLocalActions extends LocalActionDefault {

  /**
   * {@inheritdoc}
   */
  public function getRouteParameters(RouteMatchInterface $route_match) {
    return [
      'node' => $route_match->getParameter('node')->id(),
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function getOptions(RouteMatchInterface $route_match) {
    return array_merge_recursive(
      parent::getOptions($route_match),
      [
        'query' => [
          'destination' => Url::fromRouteMatch($route_match)->toString(),
        ],
      ]
    );
  }

}
