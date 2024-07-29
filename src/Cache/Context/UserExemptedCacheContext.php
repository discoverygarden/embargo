<?php

namespace Drupal\embargo\Cache\Context;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Context based of the given user being exempt from _any_ embargoes.
 */
class UserExemptedCacheContext implements CacheContextInterface {

  /**
   * Memoize if exempted.
   *
   * @var bool
   */
  protected bool $exempted;

  /**
   * Constructor.
   */
  public function __construct(
    protected AccountInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    // No-op, other than stashing properties.
  }

  /**
   * {@inheritDoc}
   */
  public static function getLabel() {
    return \t('User, has any embargo exemptions');
  }

  /**
   * {@inheritDoc}
   */
  public function getContext() {
    return $this->isExempted() ? '1' : '0';
  }

  /**
   * {@inheritDoc}
   */
  public function getCacheableMetadata() {
    return (new CacheableMetadata())
      ->addCacheContexts([$this->isExempted() ? 'user' : 'user.permissions'])
      ->addCacheTags(['embargo_list']);
  }

  /**
   * Determine if the current user has _any_ exemptions.
   *
   * @return bool
   *   TRUE if the user is exempt to at least one embargo; otherwise, FALSE.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function isExempted() : bool {
    if (!isset($this->exempted)) {
      $results = $this->entityTypeManager->getStorage('embargo')->getQuery()
        ->accessCheck(FALSE)
        ->condition('exempt_users', $this->currentUser->id())
        ->range(0, 1)
        ->execute();
      $this->exempted = !empty($results);
    }

    return $this->exempted;
  }

}
