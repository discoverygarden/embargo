<?php

namespace Drupal\Tests\islandora_test_support\Traits;

use Drupal\file\FileInterface;
use Drupal\islandora\IslandoraUtils;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;
use Drupal\Tests\field\Traits\EntityReferenceTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\test_support\Traits\Installs\InstallsModules;
use Drupal\Tests\test_support\Traits\Support\InteractsWithEntities;

/**
 * Useful test traits for Islandora.Creates Islanodra node, media and files.
 */
trait IslandoraContentTypeTestTraits {
  use EntityReferenceTestTrait;
  use ContentTypeCreationTrait;
  use MediaTypeCreationTrait;
  use InteractsWithEntities;
  use InstallsModules;

  /**
   * Node type for node creation.
   *
   * @var \Drupal\node\NodeTypeInterface|\Drupal\node\Entity\NodeType
   */
  protected NodeTypeInterface $contentType;

  /**
   * Media type for media creation.
   *
   * @var \Drupal\media\MediaTypeInterface
   */
  protected MediaTypeInterface $mediaType;

  /**
   * {@inheritDoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function prepareIslandoraContentType() : void {
    // Create content required for creating islandora-esque data.
    $this->contentType = $this->createContentType(['type' => 'page']);
    $this->mediaType = $this->createMediaType('file');
    $this->createEntityReferenceField('media',
      $this->mediaType->id(), IslandoraUtils::MEDIA_OF_FIELD,
      "Media Of", $this->contentType->getEntityType()->getBundleOf());
  }

  /**
   * Helper; create a node.
   *
   * @return \Drupal\node\NodeInterface
   *   A created (and saved) node entity.
   */
  protected function createNode() : NodeInterface {
    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $this->createEntity('node', [
      'type' => $this->contentType->getEntityTypeId(),
      'title' => $this->randomString(),
    ]);
    return $entity;
  }

  /**
   * Helper; create a file entity.
   *
   * @return \Drupal\file\FileInterface
   *   A created (and saved) file entity.
   */
  protected function createFile() : FileInterface {
    /** @var \Drupal\file\FileInterface $entity */
    $entity = $this->createEntity('file', [
      'uri' => 'info:data/' . $this->randomMachineName(),
    ]);
    return $entity;
  }

  /**
   * Helper; create an Islandora-esque media entity.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity to which the media should refer.
   * @param \Drupal\node\NodeInterface $node
   *   A node to which the media should belong using Islandora's "media of"
   *   field.
   *
   * @return \Drupal\media\MediaInterface
   *   A created (and saved) media entity.
   */
  protected function createMedia(FileInterface $file, NodeInterface $node) : MediaInterface {
    /** @var \Drupal\media\MediaInterface $entity */
    $entity = $this->createEntity('media', [
      'bundle' => $this->mediaType->id(),
      IslandoraUtils::MEDIA_OF_FIELD => $node,
      $this->getMediaFieldName() => $file,
    ]);
    return $entity;
  }

  /**
   * Helper; get the name of the source field of our created media type.
   *
   * @return string
   *   The name of the field.
   */
  protected function getMediaFieldName() : string {
    return $this->mediaType->getSource()->getSourceFieldDefinition($this->mediaType)->getName();
  }

}
