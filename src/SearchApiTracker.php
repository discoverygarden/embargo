<?php

namespace Drupal\embargo;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\file\FileInterface;
use Drupal\islandora\IslandoraUtils;
use Drupal\islandora_hierarchical_access\LUTGeneratorInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
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
   * Memoize if we found an index requiring our index maintenance.
   *
   * @var bool
   */
  protected bool $isProcessorEnabled;

  /**
   * Helper; determine if our "embargo_processor" processor is enabled.
   *
   * If _not_ enabled, we do not have to perform the index maintenance in this
   * service.
   *
   * @return bool
   *   TRUE if the "embargo_processor" processor is enabled on an index;
   *   otherwise, FALSE.
   */
  protected function isProcessorEnabled() : bool {
    if (!isset($this->isProcessorEnabled)) {
      $this->isProcessorEnabled = FALSE;
      if (!$this->moduleHandler->moduleExists('search_api')) {
        return $this->isProcessorEnabled;
      }
      /** @var \Drupal\search_api\IndexInterface[] $indexes */
      $indexes = $this->entityTypeManager->getStorage('search_api_index')
        ->loadMultiple();
      foreach ($indexes as $index) {
        if ($index->isValidProcessor('embargo_processor')) {
          $this->isProcessorEnabled = TRUE;
          break;
        }
      }
    }

    return $this->isProcessorEnabled;
  }

  /**
   * Track the given entity (and related entities) for indexing.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to track.
   */
  public function track(EntityInterface $entity) : void {
    assert($entity instanceof EmbargoInterface);
    if (!$this->isProcessorEnabled()) {
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
  public function doTrack(ContentEntityInterface $entity) : void {
    if (!$this->isProcessorEnabled()) {
      return;
    }
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

  /**
   * Helper; get the media type with its specific interface.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media of which to get the type.
   *
   * @return \Drupal\media\MediaTypeInterface
   *   The media type of the given media.
   */
  protected function getMediaType(MediaInterface $media) : MediaTypeInterface {
    $type = $this->entityTypeManager->getStorage('media_type')->load($media->bundle());
    assert($type instanceof MediaTypeInterface);
    return $type;
  }

  /**
   * Determine if special tracking is required for this media.
   *
   * Given search_api indexes could be built specifically for files, we should
   * reset any related tracking due to the islandora_hierarchical_access
   * relations across the entity types.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media to test.
   *
   * @return bool
   *   TRUE if relevant; otherwise, FALSE.
   */
  public function isMediaRelevant(MediaInterface $media) : bool {
    if (!$this->isProcessorEnabled()) {
      return FALSE;
    }
    // No `field_media_of`, so unrelated to IHA LUT.
    if (!$media->hasField(IslandoraUtils::MEDIA_OF_FIELD)) {
      return FALSE;
    }

    $media_type = $this->getMediaType($media);
    $media_source = $media->getSource();
    if ($media_source->getSourceFieldDefinition($media_type)->getSetting('target_type') !== 'file') {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Get the file for the media.
   *
   * @param \Drupal\media\MediaInterface|null $media
   *   The media of which to get the file.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file if it could be loaded; otherwise, NULL.
   */
  public function mediaGetFile(?MediaInterface $media) : ?FileInterface {
    return $media ?
      $this->entityTypeManager->getStorage('file')->load(
        $media->getSource()->getSourceFieldValue($media)
      ) :
      NULL;
  }

  /**
   * Helper; get the containing nodes.
   *
   * @param \Drupal\media\MediaInterface|null $media
   *   The media of which to enumerate the containing node(s).
   *
   * @return \Drupal\node\NodeInterface[]
   *   The containing node(s).
   */
  protected function getMediaContainers(?MediaInterface $media) : array {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemList|null $containers */
    $containers = $media?->get(IslandoraUtils::MEDIA_OF_FIELD);
    $entities = $containers?->referencedEntities() ?? [];
    $to_return = [];
    foreach ($entities as $entity) {
      $to_return[$entity->id()] = $entity;
    }
    return $to_return;
  }

  /**
   * React to media create/update events.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media being operated on.
   */
  public function mediaWriteReaction(MediaInterface $media) : void {
    if (!$this->isMediaRelevant($media)) {
      return;
    }

    $original_file = $this->mediaGetFile($media->original ?? NULL);
    $current_file = $this->mediaGetFile($media);

    $same_file = $original_file === $current_file;

    $original_containers = $this->getMediaContainers($media->original ?? NULL);
    $current_containers = $this->getMediaContainers($media);

    $same_containers = $current_containers == array_intersect_key($current_containers, $original_containers);

    if (!($same_file && $same_containers)) {
      if ($original_file) {
        $this->doTrack($original_file);
      }
      if ($current_file) {
        $this->doTrack($current_file);
      }
    }
  }

  /**
   * React to media delete events.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity that is/was being deleted.
   */
  public function mediaDeleteReaction(MediaInterface $media) : void {
    if (!$this->isMediaRelevant($media)) {
      return;
    }

    if ($current_file = $this->mediaGetFile($media)) {
      $this->doTrack($current_file);
    }
  }

}
