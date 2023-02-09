<?php

namespace Drupal\Tests\embargo\Functional;

use Drupal\Core\Database\Database;
use Drupal\embargo\EmbargoInterface;

/**
 * Tests access queries are properly altered by embargo module.
 *
 * @group embargo
 */
class EmbargoAccessQueryTaggingAlterTest extends EmbargoFunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();
    node_access_rebuild();
    $this->embargo = $this->createEmbargo(EmbargoInterface::EMBARGO_TYPE_NODE);
  }

  /**
   * Tests 'node_access' query alter, for node without embargo.
   *
   * Verifies that a user with access
   * content permission can view a non-embargoed node.
   */
  public function testEmbargoNodeQueryAlterWithAccess() {
    $this->node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Article 2',
    ]);
    $query = Database::getConnection()->select('node', 'n')
      ->fields('n');
    $query->addTag('node_access');
    $query->addMetaData('op', 'view');
    $query->addMetaData('account', $this->user);

    $result = $query->execute()->fetchAll();
    $this->assertCount(1, $result, 'Node without embargo can be viewed.');
  }

  /**
   * Tests 'node_access' query alter, for embargoed node.
   *
   * Verifies that a user cannot view an embargoed node.
   */
  public function testNodeEmbargoNodeAccessQueryAlterAccessDenied() {
    $query = Database::getConnection()->select('node', 'n')
      ->fields('n');
    $query->addTag('node_access');
    $query->addMetaData('op', 'view');
    $query->addMetaData('account', $this->user);

    $result = $query->execute()->fetchAll();
    $this->assertCount(0, $result, 'Embargoed nodes cannot be viewed');
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
    $query = Database::getConnection()->select('files', 'f')
      ->fields('f');
    $query->addTag('file_access');
    $query->addMetaData('op', 'view');
    $query->addMetaData('account', $this->user);
    $result = $query->execute()->fetchAll();
    $this->assertCount(0, $result, 'File of embargoed nodes cannot be viewed');
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
    $this->assertCount(1, $result, 'Non embargoed nodes can be viewed');
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
    $query = Database::getConnection()->select('files', 'f')
      ->fields('f');
    $query->addTag('file_access');
    $query->addMetaData('op', 'view');
    $query->addMetaData('account', $this->user);

    $result = $query->execute()->fetchAll();
    $this->assertCount(1, $result,
      'Files of non embargoed nodes can be viewed');
  }

}
