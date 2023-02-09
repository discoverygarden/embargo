<?php

namespace Drupal\Tests\embargo\Kernel;

use Drupal\embargo\EmbargoInterface;
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
  }

  /**
   * Creates an iprange entity.
   */
  protected function createIpRangeEntity($ipRange) {
    /** @var \Drupal\embargo\Entity\IpRange $entity */
    return $this->createEntity('embargo_ip_range', [
      'label' => 'Ip Range Embargo',
      'ranges' => $ipRange,
    ]);
  }

  /**
   * Creates a file embargo.
   */
  protected function createEmbargo($type, $nid = NULL, $ipRange = NULL, $expiration_type = EmbargoInterface::EXPIRATION_TYPE_INDEFINITE) {
    // Add embargo.
    /** @var \Drupal\embargo\EmbargoInterface $entity */
    return $this->createEntity('embargo', [
      'embargo_type' => $type,
      'embargoed_node' => $nid ?? $this->node->id(),
      'expiration_type' => $expiration_type,
      'exempt_ips' => $ipRange ? $ipRange->id() : NULL,
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
