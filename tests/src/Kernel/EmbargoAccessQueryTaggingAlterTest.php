<?php

namespace Drupal\Tests\embargo\Kernel;

use Drupal\Core\Database\Database;

/**
 * Tests access queries are properly altered by embargo module.
 *
 * @group embargo
 */
class EmbargoAccessQueryTaggingAlterTest extends EmbargoKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    // Create two nodes one embargoed and one non-embargoed.
    $embargoedNode = $this->createNode();
    $this->createMedia($this->createFile(), $embargoedNode);
    $this->embargo = $this->createEmbargo($embargoedNode);

    $this->createNode();
  }

  /**
   * Tests 'node_access' query alter, for nodes with embargo.
   *
   * Verifies that a user can view non-embargoed nodes only.
   */
  public function testEmbargoNodeQueryAlterAccess() {
    $query = Database::getConnection()->select('node', 'n')
      ->fields('n');
    $query->addTag('node_access');
    $query->addMetaData('op', 'view');
    $query->addMetaData('account', $this->user);

    $result = $query->execute()->fetchAll();
    $this->assertCount(1, $result, 'User can only view non-embargoed node.');
  }

  /**
   * Tests 'media_access' query alter, for embargoed node.
   *
   * Verifies that a user cannot view the media of an embargoed node.
   */
  public function testNodeEmbargoReferencedMediaAccessQueryAlterAccessDenied() {
    $query = Database::getConnection()->select('media', 'm')
      ->fields('m');
    $query->addTag('media_access');
    $query->addMetaData('op', 'view');
    $query->addMetaData('account', $this->user);

    $result = $query->execute()->fetchAll();
    $this->assertCount(0, $result, 'Media of embargoed nodes cannot be viewed');
  }

  /**
   * Tests 'file_access' query alter, for embargoed node.
   *
   * Verifies that a user cannot view the files of an embargoed node.
   */
  public function testNodeEmbargoReferencedFileAccessQueryAlterAccessDenied() {
    $query = Database::getConnection()->select('file_managed', 'f')
      ->fields('f');
    $query->addTag('file_access');
    $query->addMetaData('op', 'view');
    $query->addMetaData('account', $this->user);
    $result = $query->execute()->fetchAll();
    $this->assertCount(1, $result, 'File of embargoed nodes cannot be viewed');
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
    $query = Database::getConnection()->select('node', 'n')
      ->fields('n');
    $query->addTag('node_access');
    $query->addMetaData('op', 'view');
    $query->addMetaData('account', $this->user);

    $result = $query->execute()->fetchAll();
    $this->assertCount(2, $result, 'Non embargoed nodes can be viewed');
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
    $query = Database::getConnection()->select('media', 'm')
      ->fields('m');
    $query->addTag('media_access');
    $query->addMetaData('op', 'view');
    $query->addMetaData('account', $this->user);

    $result = $query->execute()->fetchAll();
    $this->assertCount(1, $result,
      'Media of non embargoed nodes can be viewed');
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
    $query = Database::getConnection()->select('file_managed', 'f')
      ->fields('f');
    $query->addTag('file_access');
    $query->addMetaData('op', 'view');
    $query->addMetaData('account', $this->user);

    $result = $query->execute()->fetchAll();
    $this->assertCount(2, $result,
      'Files of non embargoed nodes can be viewed');
  }

}