<?php

namespace Drupal\Tests\embargo\Kernel;

use Drupal\embargo\EmbargoInterface;

/**
 * Test File embargo.
 *
 * @group embargo
 */
class FileEmbargoTest extends EmbargoKernelTestBase {

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
    $this->embargo = $this->createFileEmbargo();
  }

  /**
   * Creates a file embargo.
   */
  private function createFileEmbargo() {
    // Add embargo.
    /** @var \Drupal\embargo\EmbargoInterface $entity */
    return $this->createEntity('embargo', [
      'embargo_type' => EmbargoInterface::EMBARGO_TYPE_FILE,
      'embargoed_node' => $this->node->id(),
      'expiration_type' => EmbargoInterface::EXPIRATION_TYPE_INDEFINITE,
    ]);
  }

  /**
   * Test node embargo creation.
   */
  public function testCreateFileEmbargo() {
    $this->assertInstanceOf('\Drupal\embargo\EmbargoInterface', $this->createFileEmbargo());
  }

  /**
   * Test operations for node of an embargoed file.
   *
   * @dataProvider providerNodeOperations
   */
  public function testFileEmbargoedNodeAccessAllowed($operation) {
    if ($operation == 'view') {
      $this->assertTrue($this->node->access($operation, $this->user));
    }
    else {
      $this->assertFalse($this->node->access($operation, $this->user));
    }
  }

  /**
   * Test operations for file and related media on an embargoed file.
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
  public function testDeletedEmbargoedFileRelatedMediaFileAccessAllowed(
    $operation
  ) {
    $this->embargo->delete();
    $this->assertTrue($this->media->access($operation, $this->user));
    $this->assertTrue($this->file->access($operation, $this->user));
  }

}
