<?php

namespace Drupal\Tests\embargo\Kernel;

use Drupal\embargo\EmbargoInterface;
use Drupal\file\FileInterface;
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
  }

  /**
   * Tests 'node_access' query alter, for nodes with embargo.
   *
   * Verifies that a user can view non-embargoed nodes only.
   */
  public function testEmbargoNodeQueryAlterAccess() {
    $query = $this->generateNodeSelectAccessQuery($this->user);
    $result = $query->execute()->fetchAll();
    $this->assertCount(1, $result, 'User can only view non-embargoed node.');
    $this->assertEquals([$this->unembargoedNode->id()], array_column($result, 'nid'));
  }

  /**
   * Tests 'media_access' query alter, for embargoed node.
   *
   * Verifies that a user cannot view the media of an embargoed node.
   */
  public function testNodeEmbargoReferencedMediaAccessQueryAlterAccessDenied() {
    $query = $this->generateMediaSelectAccessQuery($this->user);
    $result = $query->execute()->fetchAll();
    $this->assertCount(1, $result, 'Media of embargoed nodes cannot be viewed');
    $this->assertEquals([$this->unembargoedMedia->id()], array_column($result, 'mid'));
  }

  /**
   * Tests 'file_access' query alter, for embargoed node.
   *
   * Verifies that a user cannot view the files of an embargoed node.
   */
  public function testNodeEmbargoReferencedFileAccessQueryAlterAccessDenied() {
    $query = $this->generateFileSelectAccessQuery($this->user);
    $result = $query->execute()->fetchAll();
    $this->assertCount(1, $result, 'File of embargoed nodes cannot be viewed');
    $this->assertEquals([$this->unembargoedFile->id()], array_column($result, 'fid'));
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
    $this->assertCount(2, $result, 'Non embargoed nodes can be viewed');
    $this->assertEqualsCanonicalizing([
      $this->embargoedNode->id(),
      $this->unembargoedNode->id(),
    ], array_column($result, 'nid'));
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
    $this->assertCount(2, $result,
      'Media of non embargoed nodes can be viewed');
    $this->assertEqualsCanonicalizing([
      $this->embargoedMedia->id(),
      $this->unembargoedMedia->id(),
    ], array_column($result, 'mid'));
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
    $this->assertCount(2, $result,
      'Files of non embargoed nodes can be viewed');
    $this->assertEqualsCanonicalizing([
      $this->embargoedFile->id(),
      $this->unembargoedFile->id(),
    ], array_column($result, 'fid'));
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
    $this->assertCount(1, $result,
      'Node is still embargoed.');
    $this->assertEqualsCanonicalizing([$this->unembargoedNode->id()], array_column($result, 'nid'));
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
    $this->assertCount(2, $result,
      'Embargo has been unpublished.');
    $this->assertEqualsCanonicalizing([
      $this->embargoedNode->id(),
      $this->unembargoedNode->id(),
    ], array_column($result, 'nid'));
  }

}
