<?php

namespace Drupal\embargo\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Base implementation of embargoed access.
 */
class EmbargoAccessCheck implements EmbargoAccessCheckInterface {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The request object.
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
   * Constructor for access control managers.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request being made to check access against.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The current user.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RequestStack $request_stack, AccountInterface $user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->request = $request_stack->getCurrentRequest();
    $this->user = $user;
  }

  /**
   * {@inheritdoc}
   */
  public function access(EntityInterface $entity, AccountInterface $user) {
    /** @var \Drupal\embargo\EmbargoStorage $storage */
    $storage = $this->entityTypeManager->getStorage('embargo');
    $embargoes = $storage->getApplicableNonExemptNonExpiredEmbargoes(
      $entity,
      $this->request->server->get('REQUEST_TIME'),
      $user,
      $this->request->getClientIp()
    );
    $state = AccessResult::forbiddenIf(
      !empty($embargoes),
      $this->formatPlural(
        count($embargoes),
        '1 embargo preventing access.',
        '@count embargoes preventing access.'
      )->render()
    );
    array_map([$state, 'addCacheableDependency'], $embargoes);
    return $state;
  }

}
