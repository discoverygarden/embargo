<?php

namespace Drupal\Tests\migrate_embargoes_to_embargo\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Test out permission mapping.
 *
 * @group embargo
 */
class PermissionsMapKernelTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'media',
    'file',
    'embargo',
    'migrate_embargoes_to_embargo',
    'migrate_embargoes_to_embargo_embargoes_permission_test_proxy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    $module_path = $this->getModulePath('migrate_embargoes_to_embargo');
    require_once "$module_path/migrate_embargoes_to_embargo.install";
  }

  /**
   * Test permission mapping.
   *
   * @param string $old
   *   The old permission.
   * @param string $new
   *   The new permission.
   *
   * @dataProvider mapping
   */
  public function testMapping(string $old, string $new) : void {
    $user = $this->setUpCurrentUser(['uid' => 2], [$old]);
    $this->assertTrue($user->hasPermission($old), 'The user has the permission granted.');
    $this->assertFalse($user->hasPermission($new), 'The new permission is not yet granted.');
    _migrate_embargoes_to_embargo_map_permissions();
    $this->assertTrue($user->hasPermission($new), 'The new permission has been granted.');
  }

  /**
   * Data provider of our permission mapping.
   *
   * @return array
   *   An associative array mapping an approximate name for the test case to
   *   a two-tuple representing:
   *   - the old permission from which we are mapping; and,
   *   - the new permission to which we are mapping.
   */
  public function mapping() : array {
    return [
      'admin' => ['administer embargoes settings', 'administer embargo'],
      'manage' => ['manage embargoes', 'manage embargo'],
      'bypass' => ['bypass embargoes restrictions', 'bypass embargo access'],
    ];
  }

}
