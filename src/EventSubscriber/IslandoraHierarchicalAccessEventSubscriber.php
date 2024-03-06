<?php

namespace Drupal\embargo\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\embargo\EmbargoInterface;
use Drupal\islandora_hierarchical_access\Event\Event;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Event subscriber to the islandora_hierarchical_access query alter event.
 */
class IslandoraHierarchicalAccessEventSubscriber implements EventSubscriberInterface, ContainerInjectionInterface {

  const TAG = 'embargo_access';

  /**
   * The IP of the current request.
   *
   * @var string|null
   */
  protected ?string $currentIp;

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
    $embargo_alias = $existence_query->leftJoin('embargo', 'e', '%alias.embargoed_node = lut.nid');
    $user_alias = $existence_query->leftJoin('embargo__exempt_users', 'u', "%alias.entity_id = {$embargo_alias}.id");
    $existence_or = $existence_query->orConditionGroup();

    // No embargo.
    // XXX: Might have to change to examine one of the fields outside the join
    // condition?
    $existence_or->isNull("{$embargo_alias}.embargoed_node");

    // The user is exempt from the embargo.
    $existence_or->condition("{$user_alias}.exempt_users_target_id", $this->user->id());

    // ... the incoming IP is in an exempt range; or...
    /** @var \Drupal\embargo\IpRangeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('embargo_ip_range');
    $applicable_ip_ranges = $storage->getApplicableIpRanges($this->currentIp);
    if (!empty($applicable_ip_ranges)) {
      $existence_or->condition("{$embargo_alias}.exempt_ips", array_keys($applicable_ip_ranges), 'IN');
    }

    // With embargo, without exemption.
    $embargo_and = $existence_or->andConditionGroup();

    // Has an embargo of a relevant type.
    $embargo_and->condition(
      "{$embargo_alias}.embargo_type",
      match ($event->getType()) {
        'file', 'media' => [
          EmbargoInterface::EMBARGO_TYPE_FILE,
          EmbargoInterface::EMBARGO_TYPE_NODE,
        ],
        'node' => [EmbargoInterface::EMBARGO_TYPE_NODE],
      },
      'IN',
    );

    $current_date = $this->dateFormatter->format($this->time->getRequestTime(), 'custom', DateTimeItemInterface::DATE_STORAGE_FORMAT);
    // No indefinite embargoes or embargoes expiring in the future.
    $unexpired_embargo_subquery = $this->database->select('embargo', 'ue')
      ->fields('ue', ['embargoed_node']);
    $unexpired_embargo_subquery->condition($unexpired_embargo_subquery->orConditionGroup()
      ->condition('ue.expiration_type', EmbargoInterface::EXPIRATION_TYPE_INDEFINITE)
      ->condition($unexpired_embargo_subquery->andConditionGroup()
        ->condition('ue.expiration_type', EmbargoInterface::EXPIRATION_TYPE_SCHEDULED)
        ->condition('ue.expiration_date', $current_date, '>')
      )
    );
    $embargo_and
      ->condition(
        "{$embargo_alias}.embargoed_node",
        $unexpired_embargo_subquery,
        'NOT IN',
      )
      ->condition("{$embargo_alias}.expiration_type", EmbargoInterface::EXPIRATION_TYPE_SCHEDULED)
      ->condition("{$embargo_alias}.expiration_date", $current_date, '<=');

    $existence_or->condition($embargo_and);
    $existence_query->condition($existence_or);
  }

}
