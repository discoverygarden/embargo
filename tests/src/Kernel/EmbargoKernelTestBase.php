<?php

namespace Drupal\Tests\embargo\Kernel;

use Drupal\Tests\islandora_hierarchical_access\Kernel\AbstractKernelTestBase;
use Drupal\Tests\test_support\Traits\Installs\InstallsModules;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\test_support\Traits\Support\InteractsWithAuthentication;

/**
 * Abstract kernel test base for LUT and access control testing.
 */
abstract class EmbargoKernelTestBase extends AbstractKernelTestBase {
  use InstallsModules;
  use UserCreationTrait;
  use InteractsWithAuthentication;

  /**
   * {@inheritDoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function setUp() : void {
    parent::setUp();
    $this->installModuleWithDependencies(['field', 'options', 'datetime']);
    // Enable embargo and install its schema.
    $this->installModuleWithDependencies('embargo');
    $this->installEntitySchema('embargo');
    $this->installEntitySchema('embargo_ip_range');
    $this->node = $this->createNode();
    $this->file = $this->createFile();
    $this->media = $this->createMedia($this->file, $this->node);
    $this->user = $this->setUpCurrentUser([], ['access content'], FALSE);
    $this->op = 'view';
    $this->ipRange = $this->createIpRangeEntity();
  }

  /**
   * Creates an iprange entity.
   */
  protected function createIpRangeEntity() {
    /** @var \Drupal\embargo\Entity\IpRange $entity */
    return $this->createEntity('embargo_ip_range', [
      'label' => 'Ip Range Embargo',
      'ranges' => '192.168.0.0./29',
    ]);
  }

  /**
   * Data provider for testGetTitleIsolated().
   */
  public function providerMediaFileOperations(): array {
    return [
      'View' => ['view'],
      'Download' => ['download'],
    ];
  }

  /**
   * Data provider for node operations.
   */
  public function providerNodeOperations(): array {
    return [
      'View' => ['view'],
      'Edit' => ['edit'],
      'Delete' => ['delete'],
    ];
  }

}
