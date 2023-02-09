<?php

namespace Drupal\Tests\embargo\Kernel;

use Drupal\node\NodeInterface;
use Drupal\embargo\EmbargoInterface;
use Drupal\embargo\IpRangeInterface;

/**
 * Test IpRange embargo.
 *
 * @group embargo
 */
class IpRangeEmbargoTest extends EmbargoKernelTestBase {

  /**
   * Embargo for test.
   *
   * @var \Drupal\embargo\EmbargoInterface
   */
  protected EmbargoInterface $embargo;

  /**
   * Ip range entity for test.
   *
   * @var \Drupal\embargo\IpRangeInterface
   */
  protected IpRangeInterface $ipRangeEntity;

  /**
   * Ip range for test.
   *
   * @var string
   */
  protected string $ipRange;

  /**
   * Non embargoed node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected NodeInterface $nonEmbargoedNode;

  /**
   * Embargoed node with no IP range.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected NodeInterface $embargoedNodeWithoutIpRange;

  /**
   * Embargoed node with current user IP exempt.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected NodeInterface $embargoedNodeWithCurrentIpRange;

  /**
   * Embargoed node with IP different that current user IP exempt.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected NodeInterface $embargoedNodeWithDifferentIpRange;

  /**
   * Embargo without IP range
   *
   * @var \Drupal\embargo\EmbargoInterface
   */
  protected EmbargoInterface $embargoWithoutIpRange;

  /**
   * Embargo with current IP range exempt.
   *
   * @var \Drupal\embargo\EmbargoInterface
   */
  protected EmbargoInterface $embargoWithCurrentIpRange;

  /**
   * Embargo with different than current IP range exempt.
   *
   * @var \Drupal\embargo\EmbargoInterface
   */
  protected EmbargoInterface $embargoWithDifferentIpRange;

  /**
   * Ip range entity
   *
   * @var \Drupal\embargo\IpRangeInterface
   */
  protected IpRangeInterface $currentIpRangeEntity;

  /**
   * {@inheritDoc}
   */
  public function setUp(): void {
    parent::setUp();
    $currentIp = \Drupal::request()->getClientIp();
    $this->ipRange = "$currentIp/29";
    $this->nonEmbargoedNode = $this->createNode();
    $this->embargoedNodeWithoutIpRange = $this->createNode();
    $this->embargoedNodeWithCurrentIpRange = $this->createNode();
    $this->embargoedNodeWithDifferentIpRange = $this->createNode();
    $this->currentIpRangeEntity = $this->createIpRangeEntity($this->ipRange);
    $this->embargoWithoutIpRange = $this->createEmbargo(EmbargoInterface::EMBARGO_TYPE_NODE, $this->embargoedNodeWithoutIpRange->id(), EmbargoInterface::EXPIRATION_TYPE_INDEFINITE);
    $this->embargoWithCurrentIpRange = $this->createEmbargo(EmbargoInterface::EMBARGO_TYPE_NODE, $this->embargoedNodeWithCurrentIpRange->id(), $this->currentIpRangeEntity);
    $this->embargoWithDifferentIpRange = $this->createEmbargo(EmbargoInterface::EMBARGO_TYPE_NODE, $this->embargoedNodeWithDifferentIpRange->id(), $this->createIpRangeEntity('0.0.0.0.1/29'));
  }

  /**
   * Test creation of Ip range entity.
   */
  public function testIpRangeCreation() {
    $this->assertInstanceOf('\Drupal\embargo\IpRangeInterface',
      $this->createIpRangeEntity($this->ipRange));
  }

  /**
   * Test view operation on an embargoed node with a blocked IP address.
   */
  public function testIpRangeEmbargoNodeAccess() {
    $this->assertTrue($this->nonEmbargoedNode->access('view', $this->user));
    $this->assertFalse($this->embargoedNodeWithoutIpRange->access('view', $this->user));
    $this->assertFalse($this->embargoedNodeWithDifferentIpRange->access('view', $this->user));
    $this->assertTrue($this->embargoedNodeWithCurrentIpRange->access('view', $this->user));
  }

}
