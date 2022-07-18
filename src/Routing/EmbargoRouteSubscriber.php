<?php

namespace Drupal\embargo\Routing;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\embargo\EmbargoStorage;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class EmbargoRouteSubscriber extends RouteSubscriberBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Subscriber for Fixity Check routes.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_manager) {
    $this->entityTypeManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $applicable_entity_types = EmbargoStorage::applicableEntityTypes();
    $definitions = $this->entityTypeManager->getDefinitions();
    foreach ($applicable_entity_types as $entity_type_id) {
      $entity_type = $definitions[$entity_type_id];
      if ($route = $this->getEmbargoesRoute($entity_type)) {
        $collection->add("entity.$entity_type_id.embargoes", $route);
      }
    }
    // Make all embargo entity based routes admin routes.
    foreach ($collection->all() as $name => $route) {
      if (str_starts_with($name, "entity.embargo")) {
        $route->setOption('_admin_route', TRUE);
      }
    }
    // Make 'canonical' routes point to the 'edit_form' so canonical links when
    // display direct the user to the edit form for convenience.
    foreach (['embargo', 'embargo_ip_range'] as $entity_type) {
      $edit_form = $collection->get("entity.{$entity_type}.edit_form");
      $collection->add("entity.{$entity_type}.canonical", $edit_form);
    }
    // Make add embargo-node-form point to add form.
    $add_form = clone $collection->get("entity.embargo.add_form");
    $add_form->setPath($definitions['embargo']->getLinkTemplate('embargo-node-form'));
    $collection->add("entity.embargo.embargo_node_form", $add_form);
  }

  /**
   * Gets the embargoes route for the given entity type.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   *
   * @return \Symfony\Component\Routing\Route|null
   *   The generated route, if available.
   */
  protected function getEmbargoesRoute(EntityTypeInterface $entity_type) {
    if ($embargoes = $entity_type->getLinkTemplate('embargoes')) {
      $entity_type_id = $entity_type->id();
      $route = new Route($embargoes);
      $route
        ->addDefaults([
          '_controller' => '\Drupal\embargo\Controller\EmbargoController::entityEmbargoes',
          '_title' => 'Embargoes',
        ])
        ->addRequirements([
          '_permission' => 'manage embargoes',
        ])
        ->setOption('_admin_route', TRUE)
        ->setOption('_embargo_type_id', $entity_type_id)
        ->setOption('parameters', [
          $entity_type_id => ['type' => 'entity:' . $entity_type_id],
        ]);
      return $route;
    }
  }

}
