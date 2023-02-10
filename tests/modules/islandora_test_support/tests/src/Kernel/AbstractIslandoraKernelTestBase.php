<?php

namespace Drupal\Tests\islandora_test_support\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\islandora_test_support\Traits\IslandoraContentTypeTestTraits;

/**
 * Abstract class for creating islandora objects.
 */
abstract class AbstractIslandoraKernelTestBase extends KernelTestBase {
  use IslandoraContentTypeTestTraits;

  /**
   * {@inheritdoc}
   */
  public static array $modulesToInstall = [
    'user',
    'node',
    'media',
    'field',
    'file',
    'image',
    'system',
    'text',
  ];

  /**
   * {@inheritdoc}
   */
  public static array $entityTypes = [
    'node',
    'media',
    'file',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  public static array $schemasToInstall = [
    'node' => 'node_access',
    'file' => 'file_usage',
    'user' => 'users_data',
  ];

  /**
   * {@inheritDoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function setUp(): void {
    parent::setUp();
    // Install required modules with dependencies.
    $this->installModulesWithDependencies(self::$modulesToInstall);

    $this->installConfig([
      'node',
      'user',
    ]);

    // Install schemas for node, file and user.
    foreach (self::$schemasToInstall as $module => $schema) {
      $this->installSchema($module, $schema);
    }

    // Install entity schemas for node, file and media.
    foreach (self::$entityTypes as $entityType) {
      $this->installEntitySchema($entityType);
    }

    $this->prepareIslandoraContentType();
  }

}
