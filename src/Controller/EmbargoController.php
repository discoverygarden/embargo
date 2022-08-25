<?php

namespace Drupal\embargo\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\embargo\EmbargoInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays a table of applicable embargoes for the given entity.
 *
 * Essentially, a filtered version of \Drupal\embargo\EmbargoListBuilder.
 */
class EmbargoController extends ControllerBase {

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Information about the embargo entity type.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface
   */
  protected $entityType;

  /**
   * Embargo entity storage.
   *
   * @var \Drupal\embargo\EmbargoStorageInterface
   */
  protected $storage;

  /**
   * The embargo content entity field definitions to display in the table.
   *
   * @var \Drupal\Core\Field\FieldDefinitionInterface[]
   */
  protected $fields;

  /**
   * Constructs an embargoes node controller.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $field_manager
   *   An entity field manager.
   */
  public function __construct(RendererInterface $renderer, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $field_manager) {
    $this->renderer = $renderer;
    $this->entityType = $entity_type_manager->getDefinition('embargo');
    $this->storage = $entity_type_manager->getStorage($this->entityType->id());
    $this->fields = $field_manager->getFieldDefinitions($this->entityType->id(), NULL);
    // Exclude the node reference field as we are rendering only those
    // embargoes which apply to the entity provided in the url.
    foreach ($this->fields as $field_name => $definition) {
      if (
        $definition->getType() === 'entity_reference' &&
        $definition->getSetting('target_type') === 'node'
      ) {
        unset($this->fields[$field_name]);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) : self {
    return new static(
      $container->get('renderer'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * Builds the headers for the table.
   *
   * @see \Drupal\Core\Entity\EntityListBuilder::buildHeader()
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   A list of headers to use for the columns.
   */
  protected function buildHeader() : array {
    $header = [];
    foreach ($this->fields as $field => $definition) {
      if (!is_null($definition->getDisplayOptions('view'))) {
        $header[$field] = $definition->getLabel();
      }
    }
    $header['operations'] = $this->t('Operations');
    return $header;
  }

  /**
   * Builds a row for the given embargo entity.
   *
   * @see \Drupal\Core\Entity\EntityListBuilder::buildRow()
   *
   * @return array
   *   A render array structure of fields for this entity.
   */
  public function buildRow(EmbargoInterface $entity) : array {
    $columns = [];
    foreach ($this->fields as $field => $definition) {
      if (!is_null($definition->getDisplayOptions('view'))) {
        $columns[$field]['data'] = $entity->{$field}->view('default');
      }
    }
    $columns['operations']['data'] = $this->buildOperations($entity);
    return $columns;
  }

  /**
   * Ensures that a destination is present on the given URL.
   *
   * @param \Drupal\Core\Url $url
   *   The URL object to which the destination should be added.
   *
   * @return \Drupal\Core\Url
   *   The updated URL object.
   */
  protected function ensureDestination(Url $url) {
    return $url->mergeOptions(['query' => $this->getRedirectDestination()->getAsArray()]);
  }

  /**
   * Gets this list's default operations.
   *
   * @param \Drupal\embargo\EmbargoInterface $embargo
   *   The entity the operations are for.
   *
   * @return array
   *   The array structure is identical to the return value of
   *   self::getOperations().
   *
   * @see \Drupal\Core\Entity\EntityListBuilder::getDefaultOperations()
   */
  protected function getDefaultOperations(EmbargoInterface $embargo) {
    $operations = [];
    if ($embargo->access('update') && $embargo->hasLinkTemplate('edit-form')) {
      $operations['edit'] = [
        'title' => $this->t('Edit'),
        'weight' => 10,
        'url' => $this->ensureDestination($embargo->toUrl('edit-form')),
      ];
    }
    if ($embargo->access('delete') && $embargo->hasLinkTemplate('delete-form')) {
      $operations['delete'] = [
        'title' => $this->t('Delete'),
        'weight' => 90,
        'url' => $this->ensureDestination($embargo->toUrl('delete-form')),
      ];
    }
    return $operations;
  }

  /**
   * Get operations to add to the listing.
   *
   * @see \Drupal\Core\Entity\EntityListBuilder::getOperations()
   */
  public function getOperations(EmbargoInterface $entity) {
    $operations = $this->getDefaultOperations($entity);
    $operations += $this->moduleHandler()->invokeAll('entity_operation', [$entity]);
    $this->moduleHandler->alter('entity_operation', $operations, $entity);
    uasort($operations, '\Drupal\Component\Utility\SortArray::sortByWeightElement');
    return $operations;
  }

  /**
   * Builds a renderable list of operation links for the embargo.
   *
   * @param \Drupal\embargo\EmbargoInterface $embargo
   *   The embargo on which the linked operations will be performed.
   *
   * @return array
   *   A renderable array of operation links.
   */
  public function buildOperations(EmbargoInterface $embargo) {
    return [
      '#type' => 'operations',
      '#links' => $this->getOperations($embargo),
    ];
  }

  /**
   * Gets markup for displaying embargoes on a node.
   *
   * @return array
   *   Renderable array to show the embargoes on a node.
   */
  public function embargoes(EntityInterface $entity) {
    $rows = [];
    $embargoes = $this->storage->getApplicableEmbargoes($entity);
    foreach ($embargoes as $embargo) {
      if ($row = $this->buildRow($embargo)) {
        $rows[$embargo->id()] = $row;
      }
    }
    $build = [
      '#type' => 'table',
      '#header' => $this->buildHeader(),
      '#title' => $this->t('Embargoes for %title', ['%title' => $entity->label()]),
      '#rows' => $rows,
      '#empty' => $this->t('There are no embargoes.'),
      '#cache' => [
        'keys' => [
          'entity_view', $entity->getEntityTypeId(), $entity->id(), 'embargoes',
        ],
        'contexts' => $this->entityType->getListCacheContexts(),
        'tags' => $this->entityType->getListCacheTags(),
      ],
    ];
    $this->renderer->addCacheableDependency($build, $entity);
    return $build;
  }

  /**
   * Returns the audit display for the current entity.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   A RouteMatch object.
   *
   * @return array
   *   Array of page elements to render.
   */
  public function entityEmbargoes(RouteMatchInterface $route_match) {
    $entity = $this->getEntityFromRouteMatch($route_match);
    return $this->embargoes($entity);
  }

  /**
   * Retrieves entity from route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity from the passed-in route match.
   */
  protected function getEntityFromRouteMatch(RouteMatchInterface $route_match): ?EntityInterface {
    // Option added by Route Subscriber.
    $parameter_name = $route_match->getRouteObject()->getOption('_embargo_type_id');
    return $route_match->getParameter($parameter_name);
  }

}
