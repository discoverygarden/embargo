<?php

namespace Drupal\embargo;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
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
   * Th current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $user;

  /**
   * Constructor.
   */
  public function __construct(EntityTypeInterface $entity_type, Connection $database, EntityFieldManagerInterface $entity_field_manager, CacheBackendInterface $cache, LanguageManagerInterface $language_manager, MemoryCacheInterface $memory_cache, EntityTypeBundleInfoInterface $entity_type_bundle_info, EntityTypeManagerInterface $entity_type_manager, RequestStack $request_stack, AccountInterface $user) {
    parent::__construct($entity_type, $database, $entity_field_manager, $cache, $language_manager, $memory_cache, $entity_type_bundle_info, $entity_type_manager);
    $this->request = $request_stack->getCurrentRequest();
    $this->user = $user;
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
   */
  public function getApplicableEmbargoes(EntityInterface $entity): array {
    if ($entity instanceof NodeInterface) {
      $properties = ['embargoed_node' => $entity->id()];
      return $this->loadByProperties($properties);
    }
    elseif ($entity instanceof MediaInterface) {
      // If a media entity has any field that relates to a node we check that
      // node for applicable embargoes.
      $applicable = [];
      /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $field */
      foreach ($entity->getFields() as $field) {
        if (
          !$field->isEmpty() &&
          $field instanceof EntityReferenceFieldItemListInterface &&
          $field->getFieldDefinition()->getSetting('target_type') === 'node'
        ) {
          foreach ($field->referencedEntities() as $node) {
            $applicable = array_merge($applicable, $this->getApplicableEmbargoes($node));
          }
        }
      }
      return array_unique($applicable, SORT_REGULAR);
    }
    elseif ($entity instanceof FileInterface) {
      // If a file entity is referenced by either an media or node entity we
      // recurse till we find all the applicable embargoes.
      $relationships = NestedArray::mergeDeep(
        file_get_file_references($entity),
        file_get_file_references($entity, NULL, EntityStorageInterface::FIELD_LOAD_REVISION, 'image')
      );
      $applicable = [];
      $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($relationships, \RecursiveArrayIterator::CHILD_ARRAYS_ONLY));
      foreach ($iterator as $entity) {
        $applicable = array_merge($applicable, $this->getApplicableEmbargoes($entity));
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
    return array_filter($this->getApplicableEmbargoes($entity), function ($embargo) use ($entity, $timestamp, $user, $ip): bool {
      $inactive = $embargo->expiresBefore($timestamp);
      $type_exempt = ($entity instanceof NodeInterface && $embargo->getEmbargoType() !== EmbargoInterface::EMBARGO_TYPE_NODE);
      $user_exempt = $embargo->isUserExempt($user);
      $ip_exempt = $embargo->ipIsExempt($ip);
      return !($inactive || $type_exempt || $user_exempt || $ip_exempt);
    });
  }

}
