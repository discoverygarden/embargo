<?php

namespace Drupal\embargo;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\islandora_hierarchical_access\LUTGeneratorInterface;
use Drupal\node\NodeInterface;
use Drupal\search_api\Plugin\search_api\datasource\ContentEntityTrackingManager;
use Drupal\search_api\Utility\TrackingHelperInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Search API Tracker helper.
 */
class SearchApiTracker implements ContainerInjectionInterface {

  /**
   * Constructor.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected ?ContentEntityTrackingManager $trackingManager,
    protected ?TrackingHelperInterface $trackingHelper,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
  ) {
    // No-op.
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) : self {
    return new static(
      $container->get('module_handler'),
      $container->get('search_api.entity_datasource.tracking_manager', ContainerInterface::NULL_ON_INVALID_REFERENCE),
      $container->get('search_api.tracking_helper', ContainerInterface::NULL_ON_INVALID_REFERENCE),
      $container->get('entity_type.manager'),
      $container->get('database'),
    );
  }

  /**
   * Track the given entity (and related entities) for indexing.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to track.
   */
  public function track(EntityInterface $entity) : void {
    assert($entity instanceof EmbargoInterface);
    if (!$this->moduleHandler->moduleExists('search_api')) {
      return;
    }

    // On updates, deal with the original value, in addition to the new.
    if (isset($entity->original)) {
      $this->track($entity->original);
    }

    if (!($node = $entity->getEmbargoedNode())) {
      // No embargoed node?
      return;
    }

    assert($node instanceof NodeInterface);

    $this->doTrack($node);
    $this->propagateChildren($node);
  }

  /**
   * Actually deal with updating search_api's trackers.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to track.
   */
  protected function doTrack(ContentEntityInterface $entity) : void {
    $this->trackingManager->trackEntityChange($entity);
    $this->trackingHelper->trackReferencedEntityUpdate($entity);
  }

  /**
   * Helper; propagate tracking updates down to related media and files.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node of which to propagate.
   */
  public function propagateChildren(NodeInterface $node) : void {
    $results = $this->database->select(LUTGeneratorInterface::TABLE_NAME, 'lut')
      ->fields('lut', ['mid', 'fid'])
      ->condition('nid', $node->id())
      ->execute();
    $media_ids = array_unique($results->fetchCol(/* 0 */));
    $file_ids = array_unique($results->fetchCol(1));

    /** @var \Drupal\media\MediaInterface $media */
    foreach ($this->entityTypeManager->getStorage('media')->loadMultiple($media_ids) as $media) {
      $this->doTrack($media);
    }
    /** @var \Drupal\file\FileInterface $file */
    foreach ($this->entityTypeManager->getStorage('file')->loadMultiple($file_ids) as $file) {
      $this->doTrack($file);
    }
  }

}
