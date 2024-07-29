<?php

namespace Drupal\embargo\EventSubscriber;

use Drupal\embargo\Event\EmbargoEvents;
use Drupal\embargo\Event\TagExclusionEvent;
use Drupal\embargo\Event\TagInclusionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Query tagging event subscriber.
 */
class TaggingEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents() : array {
    return [
      EmbargoEvents::TAG_INCLUSION => 'inclusion',
      EmbargoEvents::TAG_EXCLUSION => 'exclusion',
    ];
  }

  /**
   * Event handler; tagging inclusion event.
   *
   * @param \Drupal\embargo\Event\TagInclusionEvent $event
   *   The event being handled.
   */
  public function inclusion(TagInclusionEvent $event) : void {
    $event->getCondition()->where(strtr('!field IN (!targets)', [
      '!field' => "{$event->getEmbargoAlias()}.embargoed_node",
      '!targets' => implode(', ', $event->getTargetAliases()),
    ]));
  }

  /**
   * Event handler; tagging exclusion event.
   *
   * @param \Drupal\embargo\Event\TagExclusionEvent $event
   *   The event being handled.
   */
  public function exclusion(TagExclusionEvent $event) : void {
    // With traversing a single level of the hierarchy, it makes sense to
    // constrain to the same node as matched in the "inclusion", instead of
    // again referencing the other aliased columns dealing with node IDs.
    $event->getCondition()->where("{$event->getUnexpiredAlias()}.embargoed_node = {$event->getEmbargoAlias()}.embargoed_node");
  }

}
