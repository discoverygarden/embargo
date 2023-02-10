<?php

namespace Drupal\Tests\embargo\Kernel;

use Drupal\embargo\EmbargoInterface;

/**
 * Test Node embargo.
 *
 * @group embargo
 */
class NodeEmbargoTest extends EmbargoKernelTestBase {

  /**
   * Test node embargo creation.
   */
  public function testCreateNodeEmbargo() {
    $node = $this->createNode();
    $this->assertInstanceOf('\Drupal\embargo\EmbargoInterface', $this->createEmbargo($node, EmbargoInterface::EMBARGO_TYPE_NODE));
  }

  /**
   * Test view operation on a non-embargoed node.
   *
   * View operation on a non-embargoed node should be
   * allowed and edit/delete else should be denied.
   *
   * @dataProvider providerNodeOperations
   */
  public function testNonEmbargoedNodeAccess($operation) {
    $nonEmbargoednode = $this->createNode();
    if ($operation === 'view') {
      $this->assertTrue($nonEmbargoednode->access($operation, $this->user));
    }
    else {
      $this->assertFalse($nonEmbargoednode->access($operation, $this->user));
      $this->assertFalse($nonEmbargoednode->access($operation, $this->user));
    }
  }

  /**
   * Test operations for embargoed node.
   *
   * All operations on an embargoed node should be denied.
   *
   * @dataProvider providerNodeOperations
   */
  public function testEmbargoedNodeAccessDenied($operation) {
    $embargoednode = $this->createNode();
    $this->createEmbargo($embargoednode, EmbargoInterface::EMBARGO_TYPE_NODE);
    $this->assertFalse($embargoednode->access($operation, $this->user));
  }

  /**
   * Test operations for a node after creating and then deleting embargo.
   *
   * All operations should be denied on an embargoed node.
   * After deleting the embargo view should be allowed.
   *
   * @dataProvider providerNodeOperations
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testDeletedEmbargoNodeAccessAllowed($operation) {
    $embargoednode = $this->createNode();
    $embargo = $this->createEmbargo($embargoednode, EmbargoInterface::EMBARGO_TYPE_NODE);
    $this->assertFalse($embargoednode->access($operation, $this->user));

    // Delete the embargo.
    $embargo->delete();

    if ($operation == 'view') {
      $this->assertTrue($embargoednode->access($operation, $this->user));
    }
    else {
      $this->assertFalse($embargoednode->access($operation, $this->user));
      $this->assertFalse($embargoednode->access($operation, $this->user));
    }
  }

  /**
   * Test operations for related media and file on an embargoed node.
   *
   * On an embargoed node all operations on
   * referenced media and files should be denied.
   *
   * @dataProvider providerMediaFileOperations
   */
  public function testEmbargoedNodeRelatedMediaFileAccessDenied($operation) {
    $embargoednode = $this->createNode();
    $embargoedFile = $this->createFile();
    $embargoedMedia = $this->createMedia($embargoedFile, $embargoednode);
    $this->createEmbargo($embargoednode, EmbargoInterface::EMBARGO_TYPE_NODE);

    $this->assertFalse($embargoedMedia->access($operation, $this->user));
    $this->assertFalse($embargoedFile->access($operation, $this->user));
  }

  /**
   * Test operations for related media and file after deleting embargo.
   *
   * After deleting an embargo related file and media should be accessible.
   *
   * @dataProvider providerMediaFileOperations
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testDeletedEmbargoedNodeRelatedMediaFileAccessAllowed($operation) {
    $embargoednode = $this->createNode();
    $embargoedFile = $this->createFile();
    $embargoedMedia = $this->createMedia($embargoedFile, $embargoednode);
    $embargo = $this->createEmbargo($embargoednode, EmbargoInterface::EMBARGO_TYPE_NODE);
    $embargo->delete();

    $this->assertTrue($embargoedMedia->access($operation, $this->user));
    $this->assertTrue($embargoedFile->access($operation, $this->user));
  }

}
