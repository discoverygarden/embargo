<?php

namespace Drupal\embargo\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\embargo\EmbargoExistenceQueryTrait;
use Drupal\embargo\EmbargoInterface;
use Drupal\islandora_hierarchical_access\Event\Event;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Event subscriber to the islandora_hierarchical_access query alter event.
 */
class IslandoraHierarchicalAccessEventSubscriber implements EventSubscriberInterface, ContainerInjectionInterface {

  use EmbargoExistenceQueryTrait;

  const TAG = 'embargo_access';

  /**
   * Constructor.
   */
  public function __construct(
    protected AccountProxyInterface $user,
    protected RequestStack $requestStack,
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    protected DateFormatterInterface $dateFormatter,
  ) {
    $this->currentIp = $this->requestStack->getCurrentRequest()->getClientIp();
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) : self {
    return new static(
      $container->get('current_user'),
      $container->get('request_stack'),
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('datetime.time'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents() : array {
    return [
      Event::class => 'processEvent',
    ];
  }

  /**
   * Process the islandora_hierarchical_access query alter event.
   *
   * @param \Drupal\islandora_hierarchical_access\Event\Event $event
   *   The event to process.
   */
  public function processEvent(Event $event) : void {
    $query = $event->getQuery();
    if ($event->getQuery()->hasTag(static::TAG)) {
      return;
    }

    $query->addTag(static::TAG);

    if ($this->user->hasPermission('bypass embargo access')) {
      return;
    }

    /** @var \Drupal\Core\Database\Query\SelectInterface $existence_query */
    $existence_query = $query->getMetaData('islandora_hierarchical_access_tagged_existence_query');
    $this->applyExistenceQuery(
      $existence_query,
      'lut',
      match ($event->getType()) {
        'file', 'media' => [
          EmbargoInterface::EMBARGO_TYPE_FILE,
          EmbargoInterface::EMBARGO_TYPE_NODE,
        ],
        'node' => [EmbargoInterface::EMBARGO_TYPE_NODE],
      }
    );
  }

}
