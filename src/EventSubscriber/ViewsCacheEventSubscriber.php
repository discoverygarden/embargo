<?php

namespace Drupal\embargo\EventSubscriber;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\search_api\Event\QueryPreExecuteEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Drupal\search_api\Query\QueryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Views cache event subscriber implementation.
 */
class ViewsCacheEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents() {
    return [
      SearchApiEvents::QUERY_PRE_EXECUTE => 'preExecute',
      //'search_api.query_pre_execute.alter_cache_metadata' => ['alterCacheMetadata'],
    ];
  }

  public function preExecute(QueryPreExecuteEvent $event) {
    dsm($event->getQuery()->getTags());
    if ($event->getQuery()->hasTag('alter_cache_metadata')) {
      $this->alterCacheMetadata($event);
    }

    $query = $event->getQuery();
    if ($query instanceof RefinableCacheableDependencyInterface) {
      dsm($query->getCacheContexts());
      dsm($query->getCacheTags());
      dsm($query->getCacheMaxAge());
    }
  }

  /**
   * Alter cache metadata.
   *
   * @param \Drupal\search_api\Event\QueryPreExecuteEvent $event
   *   The pre-execution event.
   */
  public function alterCacheMetadata(QueryPreExecuteEvent $event) : void {
    $query = $event->getQuery();
    if (!($query instanceof RefinableCacheableDependencyInterface)) {
      dsm('blah');
      // Cannot add metadata to it.
      return;
    }
    if ($this->containsRelevantIndex($query)) {
      //$query->add
    }
  }

  protected function containsRelevantIndex(QueryInterface $query) : bool {
    dsm($query->getTags());
    return FALSE;
  }

}
