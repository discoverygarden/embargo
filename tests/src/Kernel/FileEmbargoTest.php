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
   * Test node embargo creation.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testCreateFileEmbargo() {
    $node = $this->createNode();
    $this->createMedia($this->createFile(), $node);
    $this->assertInstanceOf(EmbargoInterface::class, $this->createEmbargo($node, EmbargoInterface::EMBARGO_TYPE_FILE));
  }

  /**
   * Test operations for node of an embargoed file.
   *
   * View operation should be allowed on the node.
   *
   * @dataProvider providerNodeOperations
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testFileEmbargoedNodeAccessAllowed($operation) {
    $node = $this->createNode();
    $this->createMedia($this->createFile(), $node);
    $this->createEmbargo($node, EmbargoInterface::EMBARGO_TYPE_FILE);

    if ($operation == 'view') {
      $this->assertTrue($node->access($operation, $this->user));
    }
    else {
      $this->assertFalse($node->access($operation, $this->user));
    }
  }

  /**
   * Test operations for file and related media on an embargoed file.
   *
   * All operations should be denied on embargoed file and media.
   *
   * @dataProvider providerMediaFileOperations
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testEmbargoedNodeRelatedMediaFileAccessDenied($operation) {
    $node = $this->createNode();
    $file = $this->createFile();
    $media = $this->createMedia($file, $node);
    $this->createEmbargo($node, EmbargoInterface::EMBARGO_TYPE_FILE);

    $this->assertFalse($media->access($operation, $this->user));
    $this->assertFalse($file->access($operation, $this->user));
  }

  /**
   * Test operations for related media and file after deleting embargo.
   *
   * After deleting a file embargo all operations should be allowed.
   *
   * @dataProvider providerMediaFileOperations
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function testDeletedEmbargoedFileRelatedMediaFileAccessAllowed(
    $operation,
  ) {
    $node = $this->createNode();
    $file = $this->createFile();
    $media = $this->createMedia($file, $node);

    $fileEmbargo = $this->createEmbargo($node, EmbargoInterface::EMBARGO_TYPE_FILE);
    $fileEmbargo->delete();

    $this->assertTrue($media->access($operation, $this->user));

    $this->assertTrue($file->access($operation, $this->user));
  }

}
