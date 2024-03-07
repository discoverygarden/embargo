<?php

namespace Drupal\Tests\embargo\Kernel;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\embargo\EmbargoInterface;
use Drupal\embargo\Entity\IpRange;
use Drupal\embargo\IpRangeInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\islandora_test_support\Kernel\AbstractIslandoraKernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Abstract kernel test base for LUT and access control testing.
 */
abstract class EmbargoKernelTestBase extends AbstractIslandoraKernelTestBase {
  use UserCreationTrait;

  /**
   * User object to use across requests, with correct permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field', 'options', 'datetime', 'search_api'];

  /**
   * Sets up the basic modules and schemas for testing embargoes.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function setUp() : void {
    parent::setUp();
    $this->enableModules(['islandora_hierarchical_access', 'embargo']);
    $this->installSchema('islandora_hierarchical_access', ['islandora_hierarchical_access_lut']);
    $this->installEntitySchema('embargo');
    $this->installEntitySchema('embargo_ip_range');
    $this->user = $this->setUpCurrentUser([], ['access content', 'view media'], FALSE);
  }

  /**
   * Creates an iprange entity.
   *
   * @var string $ipRange
   *
   * @return \Drupal\embargo\Entity\IpRange
   *   The created iprange entity.
   */
  protected function createIpRangeEntity(string $ipRange): IpRange {
    /** @var \Drupal\embargo\Entity\IpRange $entity */
    $entity = $this->createEntity('embargo_ip_range', [
      'label' => 'Ip Range Embargo',
      'ranges' => $ipRange,
    ]);

    return $entity;
  }

  /**
   * Creates an embargo entity.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to be embargoed.
   * @param string|null $type
   *   Embargo type - node or file.
   * @param \Drupal\embargo\IpRangeInterface|null $ipRange
   *   Exempt IP Range.
   * @param string|null $expirationType
   *   Types of embargo - scheduled or indefinite.
   *
   * @return \Drupal\embargo\EmbargoInterface
   *   An embargo entity.
   */
  protected function createEmbargo(NodeInterface $node, ?string $type = EmbargoInterface::EMBARGO_TYPE_NODE, ?IpRangeInterface $ipRange = NULL, ?string $expirationType = EmbargoInterface::EXPIRATION_TYPE_INDEFINITE): EmbargoInterface {
    /** @var \Drupal\embargo\EmbargoInterface $entity */
    $entity = $this->createEntity('embargo', [
      'embargo_type' => $type,
      'embargoed_node' => $node->id(),
      'expiration_type' => $expirationType,
      'exempt_ips' => $ipRange ? $ipRange->id() : NULL,
    ]);
    return $entity;
  }

  /**
   * Returns an embargo set to be unpublished in the future.
   *
   * @param \Drupal\embargo\EmbargoInterface $embargo
   *   Embargo Entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function setEmbargoFutureUnpublishDate(EmbargoInterface &$embargo) {
    $embargo->setExpirationDate((new DrupalDateTime('+3 days')))->save();
  }

  /**
   * Returns an embargo set to be unpublished in the past.
   *
   * @param \Drupal\embargo\EmbargoInterface $embargo
   *   Embargo Entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function setEmbargoPastUnpublishDate(EmbargoInterface &$embargo) {
    $embargo->setExpirationDate((new DrupalDateTime('-3 days')))->save();
  }

  /**
   * Data provider for media operations.
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
