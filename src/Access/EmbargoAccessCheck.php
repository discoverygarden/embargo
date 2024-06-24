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
   * Th current user.
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
    $type = $this->entityTypeManager->getDefinition('embargo');
    $state = AccessResult::neutral()
      ->addCacheTags($type->getListCacheTags())
      ->addCacheContexts($type->getListCacheContexts());

    if ($user->hasPermission('bypass embargo access')) {
      return $state->setReason('User has embargo bypass permission.')
        ->addCacheContexts(['user.permissions']);
    }

    /** @var \Drupal\embargo\EmbargoStorage $storage */
    $storage = $this->entityTypeManager->getStorage('embargo');
    $related_embargoes = $storage->getApplicableEmbargoes($entity);
    if (empty($related_embargoes)) {
      return $state->setReason('No embargo statements for the given entity.');
    }

    array_map([$state, 'addCacheableDependency'], $related_embargoes);

    $embargoes = $storage->getApplicableNonExemptNonExpiredEmbargoes(
      $entity,
      $this->request->server->get('REQUEST_TIME'),
      $user,
      $this->request->getClientIp()
    );
    return $state->andIf(AccessResult::forbiddenIf(
      !empty($embargoes),
      $this->formatPlural(
        count($embargoes),
        '1 embargo preventing access.',
        '@count embargoes preventing access.'
      )->render()
    ));

  }

}
