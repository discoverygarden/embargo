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
   * Embargo for test.
   *
   * @var \Drupal\embargo\EmbargoInterface
   */
  protected EmbargoInterface $embargo;

  /**
   * {@inheritDoc}
   */
  public function setUp(): void {
    parent::setUp();
    $this->embargo = $this->createEmbargo(EmbargoInterface::EMBARGO_TYPE_NODE);
  }

  /**
   * Test node embargo creation.
   */
  public function testCreateNodeEmbargo() {
   $this->assertInstanceOf('\Drupal\embargo\EmbargoInterface', $this->createEmbargo(EmbargoInterface::EMBARGO_TYPE_NODE));
  }

  /**
   * Test view operation on a non-embargoed node.
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
   * @dataProvider providerNodeOperations
   */
  public function testEmbargoedNodeAccessDenied($operation) {
    $this->assertFalse($this->node->access($operation, $this->user));
  }

  /**
   * Test operations for a node after deleting embargo.
   *
   * @dataProvider providerNodeOperations
   */
  public function testDeletedEmbargoNodeAccessAllowed($operation) {
    $this->embargo->delete();
    if ($operation == 'view') {
      $this->assertTrue($this->node->access($operation, $this->user));
    }
    else {
      $this->assertFalse($this->node->access($operation, $this->user));
      $this->assertFalse($this->node->access($operation, $this->user));
    }
  }

  /**
   * Test operations for related media and file on an embargoed node.
   *
   * @dataProvider providerMediaFileOperations
   */
  public function testEmbargoedNodeRelatedMediaFileAccessDenied($operation) {
    $this->assertFalse($this->media->access($operation, $this->user));
    $this->assertFalse($this->file->access($operation, $this->user));
  }

  /**
   * Test operations for related media and file after deleting embargo.
   *
   * @dataProvider providerMediaFileOperations
   */
  public function testDeletedEmbargoedNodeRelatedMediaFileAccessAllowed(
    $operation
  ) {
    $this->embargo->delete();
    $this->assertTrue($this->media->access($operation, $this->user));
    $this->assertTrue($this->file->access($operation, $this->user));
  }

}
