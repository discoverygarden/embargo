<?php

namespace Drupal\Tests\embargo\Functional;

use Drupal\embargo\EmbargoInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\file\FileInterface;
use Drupal\islandora\IslandoraUtils;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;
use Drupal\Tests\field\Traits\EntityReferenceTestTrait;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\test_support\Traits\Support\InteractsWithEntities;

/**
 * Abstract functional test base for embargo testing.
 */
abstract class EmbargoFunctionalTestBase extends BrowserTestBase {

  use EntityReferenceTestTrait;
  use ContentTypeCreationTrait;
  use MediaTypeCreationTrait;
  use InteractsWithEntities;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'file',
    'image',
    'node',
    'media',
    'system',
    'options',
    'datetime',
    'text',
    'user',
    'search',
    'islandora_hierarchical_access',
    'embargo',
  ];

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
   * Embargo object.
   *
   * @var \Drupal\embargo\EmbargoInterface
   */
  protected EmbargoInterface $embargo;

  /**
   * {@inheritDoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
    $this->mediaType = $this->createMediaType('file');
    $this->createEntityReferenceField('media',
      $this->mediaType->id(), IslandoraUtils::MEDIA_OF_FIELD,
      "Media Of", 'node');
    $this->node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Article 1',
    ]);
    $this->file = $this->createFile();
    $this->media = $this->createMedia($this->file, $this->node);
    $this->user = $this->setUpCurrentUser([], ['access content'], FALSE);
    $this->op = 'view';
  }

  /**
   * Helper; create a file entity.
   *
   * @return \Drupal\file\FileInterface
   *   A created (and saved) file entity.
   */
  protected function createFile(): FileInterface {
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
  protected function createMedia(
    FileInterface $file,
    NodeInterface $node
  ): MediaInterface {
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
  protected function getMediaFieldName(): string {
    return $this->mediaType->getSource()
      ->getSourceFieldDefinition($this->mediaType)
      ->getName();
  }

  /**
   * Creates an embargo entity.
   */
  protected function createEmbargo($type) {
    // Add embargo.
    /** @var \Drupal\embargo\EmbargoInterface $entity */
    return $this->createEntity('embargo', [
      'embargo_type' => $type,
      'embargoed_node' => $this->node->id(),
      'expiration_type' => EmbargoInterface::EXPIRATION_TYPE_INDEFINITE,
    ]);
  }

}
