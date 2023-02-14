<?php

namespace Drupal\Tests\embargo\Kernel;

use Drupal\embargo\EmbargoInterface;
use Drupal\Tests\islandora_test_support\Kernel\AbstractIslandoraKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Abstract kernel test base for LUT and access control testing.
 */
abstract class EmbargoKernelTestBase extends AbstractIslandoraKernelTestBase {
  use UserCreationTrait;

  /**
   * {@inheritDoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function setUp() : void {
    parent::setUp();
    $this->installModuleWithDependencies([
      'field', 'options', 'datetime', 'embargo',
    ]);
    $this->installEntitySchema('embargo');
    $this->installEntitySchema('embargo_ip_range');
    $this->user = $this->setUpCurrentUser([], ['access content'], FALSE);
  }

  /**
   * Creates an iprange entity.
   */
  protected function createIpRangeEntity($ipRange) {
    /** @var \Drupal\embargo\Entity\IpRange $entity */
    $entity = $this->createEntity('embargo_ip_range', [
      'label' => 'Ip Range Embargo',
      'ranges' => $ipRange,
    ]);

    return $entity;
  }

  /**
   * Creates an embargo.
   */
  protected function createEmbargo($node, $type = EmbargoInterface::EMBARGO_TYPE_NODE, $ipRange = NULL, $expiration_type = EmbargoInterface::EXPIRATION_TYPE_INDEFINITE) {
    /** @var \Drupal\embargo\EmbargoInterface $entity */
    $entity = $this->createEntity('embargo', [
      'embargo_type' => $type,
      'embargoed_node' => $node->id(),
      'expiration_type' => $expiration_type,
      'exempt_ips' => $ipRange ? $ipRange->id() : NULL,
    ]);
    return $entity;
  }

  /**
   * Data provider for testGetTitleIsolated().
   */
  protected function providerMediaFileOperations(): array {
    return [
      'View' => ['view'],
      'Download' => ['download'],
    ];
  }

  /**
   * Data provider for node operations.
   */
  protected function providerNodeOperations(): array {
    return [
      'View' => ['view'],
      'Edit' => ['edit'],
      'Delete' => ['delete'],
    ];
  }

}
