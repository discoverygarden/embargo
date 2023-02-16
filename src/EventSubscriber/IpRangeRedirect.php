<?php

namespace Drupal\embargo\EventSubscriber;

use Drupal\Core\Cache\CacheableRedirectResponse;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Request subscriber, redirects if an applicable embargo denies access.
 *
 * Only embargoes with IP Ranges are considered as applicable.
 */
class IpRangeRedirect implements EventSubscriberInterface {

  /**
   * The event exception boolean.
   *
   * @var bool
   */
  protected $eventException;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The currently logged in user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $user;

  /**
   * Stores URL generator.
   *
   * @var \Drupal\Core\Routing\UrlGeneratorInterface
   */
  protected $urlGenerator;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The currently logged in user.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $url_generator
   *   The url generator service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $user, UrlGeneratorInterface $url_generator) {
    $this->eventException = FALSE;
    $this->entityTypeManager = $entity_type_manager;
    $this->user = $user;
    $this->urlGenerator = $url_generator;
  }

  /**
   * Attaches an IP redirect to requests that require one.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event response.
   */
  public function redirectResponse(RequestEvent $event) {
    // Cycle through all attributes looking for entities in which embargoes
    // might apply. Accumulating a list of active non-exempt embargoes per
    // attribute.
    $resources = [];
    $embargoes = [];
    $request = $event->getRequest();
    /** @var \Drupal\embargo\EmbargoStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('embargo');
    foreach ($request->attributes->all() as $attribute) {
      if ($attribute instanceof EntityInterface) {
        $results = $storage->getApplicableNonExemptNonExpiredEmbargoes(
          $attribute,
          $request->server->get('REQUEST_TIME'),
          $this->user,
          $request->getClientIp()
        );
        // We only perform redirects for those embargoes that specify an IP
        // range in which a proxy url might be specified.
        $results = array_filter($results, function ($embargo) {
          return !is_null($embargo->getExemptIps());
        });
        if (!empty($results)) {
          $resources[] = $attribute;
          $embargoes = array_merge($embargoes, $results);
        }
      }
    }

    if (!empty($embargoes)) {
      $url = $this->urlGenerator->generateFromRoute('embargo.ip_access_exemption', [
        'resources' => array_map(function ($entity) {
          return $entity->label();
        }, $resources),
        'ranges' => array_map(function ($entity) {
          return $entity->getExemptIps()->id();
        }, $embargoes),
      ], ['absolute' => TRUE]);
      $redirect = new CacheableRedirectResponse($url);

      foreach ($embargoes as $embargo) {
        $redirect->addCacheableDependency($embargo);
      }
      return $redirect;
    }
    return NULL;
  }

  /**
   * IP access denied redirect on KernelEvents::EXCEPTION.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event response.
   */
  public function exceptionRedirect(RequestEvent $event) {
    // Boolean indicating event exception. Prevents potential infinite
    // redirect loop on KernelEvents::REQUEST.
    $this->eventException = TRUE;

    if ($response = $this->redirectResponse($event)) {
      $event->setResponse($response);
    }
  }

  /**
   * IP access denied redirect on KernelEvents::REQUEST.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The event response.
   */
  public function requestRedirect(RequestEvent $event) {
    if (!$this->eventException) {
      if ($response = $this->redirectResponse($event)) {
        $event->setResponse($response);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::EXCEPTION => [['exceptionRedirect']],
      KernelEvents::REQUEST => [['requestRedirect']],
    ];
  }

}
