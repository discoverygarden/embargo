<?php

namespace Drupal\embargo\Plugin\Menu;

use Drupal\Core\Menu\LocalActionDefault;
use Drupal\Core\Routing\RouteMatchInterface;

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

}
