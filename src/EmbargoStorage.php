<?php

namespace Drupal\embargo;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Drupal\islandora_hierarchical_access\LUTHelper;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Storage for embargo entities.
 */
class EmbargoStorage extends SqlContentEntityStorage implements EmbargoStorageInterface {

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $user;

  /**
   * The lookup table helper.
   *
   * @var \Drupal\islandora_hierarchical_access\LUTHelper
   */
  protected $lutHelper;

  /**
   * Constructor.
   */
  public function __construct(EntityTypeInterface $entity_type, Connection $database, EntityFieldManagerInterface $entity_field_manager, CacheBackendInterface $cache, LanguageManagerInterface $language_manager, MemoryCacheInterface $memory_cache, EntityTypeBundleInfoInterface $entity_type_bundle_info, EntityTypeManagerInterface $entity_type_manager, RequestStack $request_stack, AccountInterface $user, LUTHelper $lutHelper) {
    parent::__construct($entity_type, $database, $entity_field_manager, $cache, $language_manager, $memory_cache, $entity_type_bundle_info, $entity_type_manager);
    $this->request = $request_stack->getCurrentRequest();
    $this->user = $user;
    $this->lutHelper = $lutHelper;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('database'),
      $container->get('entity_field.manager'),
      $container->get('cache.entity'),
      $container->get('language_manager'),
      $container->get('entity.memory_cache'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('current_user'),
      $container->get('islandora_hierarchical_access.lut_helper'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function applicableEntityTypes(): array {
    return [
      'node',
      'media',
      'file',
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Exception
   */
  public function getApplicableEmbargoes(EntityInterface $entity): array {
    if ($entity instanceof NodeInterface) {
      $properties = ['embargoed_node' => $entity->id()];
      return $this->loadByProperties($properties);
    }
    elseif ($entity instanceof MediaInterface
      || $entity instanceof FileInterface) {
      // If a media or file entity has any field that relates to a node we check that
      // node for applicable embargoes.
      $applicable = [];
      $conditionFieldName = $entity instanceof MediaInterface ? 'mid' : 'fid';
      $referencedNodeIds = $this->lutHelper->lookUpFields(
        'nid',
        [
          'field' => $conditionFieldName,
          'value' => $entity->id(),
        ]);
      $referencedNodes = $this->entityTypeManager->getStorage('node')->loadMultiple($referencedNodeIds);
      foreach ($referencedNodes as $node) {
        $applicable = array_merge($applicable, $this->getApplicableEmbargoes($node));
      }

      return array_unique($applicable, SORT_REGULAR);
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getApplicableNonExemptNonExpiredEmbargoes(EntityInterface $entity, ?int $timestamp = NULL, ?AccountInterface $user = NULL, ?string $ip = NULL): array {
    $timestamp = $timestamp ?? $this->request->server->get('REQUEST_TIME');
    $user = $user ?? $this->user;
    $ip = $ip ?? $this->request->getClientIp();
    return array_filter(
      $this->getApplicableEmbargoes($entity), function ($embargo) use ($entity, $timestamp, $user, $ip): bool {
        $inactive = $embargo->expiresBefore($timestamp);
        $type_exempt = ($entity instanceof NodeInterface && $embargo->getEmbargoType() !== EmbargoInterface::EMBARGO_TYPE_NODE);
        $user_exempt = $embargo->isUserExempt($user);
        $ip_exempt = $embargo->ipIsExempt($ip);
        return !($inactive || $type_exempt || $user_exempt || $ip_exempt);
      }
    );
  }

}
