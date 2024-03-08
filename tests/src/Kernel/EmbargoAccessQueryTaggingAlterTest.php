<?php

namespace Drupal\Tests\embargo\Kernel;

use Drupal\embargo\EmbargoInterface;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\islandora_test_support\Traits\DatabaseQueryTestTraits;

/**
 * Tests access queries are properly altered by embargo module.
 *
 * @group embargo
 */
class EmbargoAccessQueryTaggingAlterTest extends EmbargoKernelTestBase {
  use DatabaseQueryTestTraits;

  /**
   * Test embargo instance.
   *
   * @var \Drupal\embargo\EmbargoInterface
   */
  protected EmbargoInterface $embargo;

  /**
   * Embargoed node from ::setUp().
   *
   * @var \Drupal\node\NodeInterface
   */
  protected NodeInterface $embargoedNode;

  /**
   * Embargoed media from ::setUp().
   *
   * @var \Drupal\media\MediaInterface
   */
  protected MediaInterface $embargoedMedia;

  /**
   * Embargoed file from ::setUp().
   *
   * @var \Drupal\file\FileInterface
   */
  protected FileInterface $embargoedFile;

  /**
   * Unembargoed node from ::setUp().
   *
   * @var \Drupal\node\NodeInterface
   */
  protected NodeInterface $unembargoedNode;

  /**
   * Unembargoed media from ::setUp().
   *
   * @var \Drupal\media\MediaInterface
   */
  protected MediaInterface $unembargoedMedia;

  /**
   * Unembargoed file from ::setUp().
   *
   * @var \Drupal\file\FileInterface
   */
  protected FileInterface $unembargoedFile;

  /**
   * Unassociated node from ::setUp().
   *
   * @var \Drupal\node\NodeInterface
   */
  protected NodeInterface $unassociatedNode;

  /**
   * Unassociated media from ::setUp().
   *
   * @var \Drupal\media\MediaInterface
   */
  protected MediaInterface $unassociatedMedia;

  /**
   * Unassociated file from ::setUp().
   *
   * @var \Drupal\file\FileInterface
   */
  protected FileInterface $unassociatedFile;

  /**
   * Lazily created "default thumbnail" image file for (file) media.
   *
   * @var \Drupal\file\FileInterface
   * @see https://git.drupalcode.org/project/drupal/-/blob/cd2c8e49c861a70b0f39b17c01051b16fd6a2662/core/modules/media/src/Entity/Media.php#L203-208
   */
  protected FileInterface $mediaTypeDefaultFile;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    // Create two nodes one embargoed and one non-embargoed.
    $this->embargoedNode = $this->createNode();
    $this->embargoedMedia = $this->createMedia($this->embargoedFile = $this->createFile(), $this->embargoedNode);
    $this->embargo = $this->createEmbargo($this->embargoedNode);

    $this->unembargoedNode = $this->createNode();
    $this->unembargoedMedia = $this->createMedia($this->unembargoedFile = $this->createFile(), $this->unembargoedNode);

    $this->unassociatedNode = $this->createNode();
    $this->unassociatedMedia = Media::create([
      'bundle' => $this->createMediaType('file', ['id' => 'file_two'])->id(),
    ])->setPublished();
    $this->unassociatedMedia->save();
    $this->unassociatedFile = $this->createFile();

    // XXX: Media lazily creates a "default thumbnail" image file by default.
    // @see https://git.drupalcode.org/project/drupal/-/blob/cd2c8e49c861a70b0f39b17c01051b16fd6a2662/core/modules/media/src/Entity/Media.php#L203-208
    $files = $this->storage('file')->loadByProperties(['filename' => 'generic.png']);
    $this->assertCount(1, $files, 'only the one generic file.');
    $this->mediaTypeDefaultFile = reset($files);
  }

  /**
   * Tests 'node_access' query alter, for nodes with embargo.
   *
   * Verifies that a user can view non-embargoed nodes only.
   */
  public function testEmbargoNodeQueryAlterAccess() {
    $query = $this->generateNodeSelectAccessQuery($this->user);
    $result = $query->execute()->fetchAll();

    $ids = array_column($result, 'nid');
    $this->assertNotContains($this->embargoedNode->id(), $ids, 'does not contain embargoed node');
    $this->assertContains($this->unembargoedNode->id(), $ids, 'contains unembargoed node');
    $this->assertContains($this->unassociatedNode->id(), $ids, 'contains unassociated node');
  }

  /**
   * Tests 'media_access' query alter, for embargoed node.
   *
   * Verifies that a user cannot view the media of an embargoed node.
   */
  public function testNodeEmbargoReferencedMediaAccessQueryAlterAccessDenied() {
    $query = $this->generateMediaSelectAccessQuery($this->user);
    $result = $query->execute()->fetchAll();

    $ids = array_column($result, 'mid');
    $this->assertNotContains($this->embargoedMedia->id(), $ids, 'does not contain embargoed media');
    $this->assertContains($this->unembargoedMedia->id(), $ids, 'contains unembargoed media');
    $this->assertContains($this->unassociatedMedia->id(), $ids, 'contains unassociated media');
  }

  /**
   * Tests 'file_access' query alter, for embargoed node.
   *
   * Verifies that a user cannot view the files of an embargoed node.
   */
  public function testNodeEmbargoReferencedFileAccessQueryAlterAccessDenied() {
    $query = $this->generateFileSelectAccessQuery($this->user);
    $result = $query->execute()->fetchAll();

    $ids = array_column($result, 'fid');
    $this->assertNotContains($this->embargoedFile->id(), $ids, 'does not contain embargoed file');
    $this->assertContains($this->unembargoedFile->id(), $ids, 'contains unembargoed file');
    $this->assertContains($this->unassociatedFile->id(), $ids, 'contains unassociated file');
    $this->assertContains($this->mediaTypeDefaultFile->id(), $ids, 'contains default mediatype file');
  }

  /**
   * Tests 'node_access' query alter, for non embargoed node.
   *
   * Verifies that a user can view an un-embargoed node.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testDeletedNodeEmbargoNodeAccessQueryAlterAccessAllowed() {
    $this->embargo->delete();
    $query = $this->generateNodeSelectAccessQuery($this->user);

    $result = $query->execute()->fetchAll();
    $ids = array_column($result, 'nid');
    $this->assertContains($this->embargoedNode->id(), $ids, 'contains formerly embargoed node');
    $this->assertContains($this->unembargoedNode->id(), $ids, 'contains unembargoed node');
    $this->assertContains($this->unassociatedNode->id(), $ids, 'contains unassociated node');
  }

  /**
   * Tests 'media_access' query alter, for un-embargoed node.
   *
   * Verifies that a user can view media of an un-embargoed node.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testDeletedNodeEmbargoMediaAccessQueryAlterAccessAllowed() {
    $this->embargo->delete();
    $query = $this->generateMediaSelectAccessQuery($this->user);
    $result = $query->execute()->fetchAll();

    $ids = array_column($result, 'mid');
    $this->assertContains($this->embargoedMedia->id(), $ids, 'contains formerly embargoed media');
    $this->assertContains($this->unembargoedMedia->id(), $ids, 'contains unembargoed media');
    $this->assertContains($this->unassociatedMedia->id(), $ids, 'contains unassociated media');
  }

  /**
   * Tests 'file_access' query alter, for un-embargoed node.
   *
   * Verifies that a user can view files of an un-embargoed node.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testDeletedNodeEmbargoFileAccessQueryAlterAccessAllowed() {
    $this->embargo->delete();

    $query = $this->generateFileSelectAccessQuery($this->user);
    $result = $query->execute()->fetchAll();

    $ids = array_column($result, 'fid');
    $this->assertContains($this->embargoedFile->id(), $ids, 'contains formerly embargoed file');
    $this->assertContains($this->unembargoedFile->id(), $ids, 'contains unembargoed file');
    $this->assertContains($this->unassociatedFile->id(), $ids, 'contains unassociated file');
    $this->assertContains($this->mediaTypeDefaultFile->id(), $ids, 'contains default mediatype file');
  }

  /**
   * Tests embargo scheduled to be unpublished in the future.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testPublishScheduledEmbargoAccess() {
    // Create an embargo scheduled to be unpublished in the future.
    $this->setEmbargoFutureUnpublishDate($this->embargo);

    $result = $this->generateNodeSelectAccessQuery($this->user)->execute()->fetchAll();

    $ids = array_column($result, 'nid');
    $this->assertNotContains($this->embargoedNode->id(), $ids, 'does not contain embargoed node');
    $this->assertContains($this->unembargoedNode->id(), $ids, 'contains unembargoed node');
    $this->assertContains($this->unassociatedNode->id(), $ids, 'contains unassociated node');
  }

  /**
   * Tests embargo scheduled to be unpublished in the past.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testUnpublishScheduledEmbargoAccess() {
    $this->embargo->setExpirationType(EmbargoInterface::EXPIRATION_TYPE_SCHEDULED)->save();
    // Create an embargo scheduled to be unpublished in the future.
    $this->setEmbargoPastUnpublishDate($this->embargo);

    $result = $this->generateNodeSelectAccessQuery($this->user)->execute()->fetchAll();

    $ids = array_column($result, 'nid');
    $this->assertContains($this->embargoedNode->id(), $ids, 'contains node with expired embargo');
    $this->assertContains($this->unembargoedNode->id(), $ids, 'contains unembargoed node');
    $this->assertContains($this->unassociatedNode->id(), $ids, 'contains unassociated node');
  }

}
