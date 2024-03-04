<?php

namespace Drupal\embargo\EventSubscriber;

use Drupal\search_api\Event\MappingForeignRelationshipsEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Inform search_api of entity_reference structures.
 */
class TrackingEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents() : array {
    return [
      SearchApiEvents::MAPPING_FOREIGN_RELATIONSHIPS => 'foreignRelationshipMap',
    ];
  }

  /**
   * Inform search_api of our computed entity_reference listings.
   *
   * @param \Drupal\search_api\Event\MappingForeignRelationshipsEvent $event
   *   The event by which to inform.
   */
  public function foreignRelationshipMap(MappingForeignRelationshipsEvent $event) : void {
    $mapping =& $event->getForeignRelationshipsMapping();
    $index = $event->getIndex();
    foreach ($index->getDatasources() as $id => $datasource) {
      if (!in_array($datasource->getEntityTypeId(), ['file', 'media', 'node'])) {
        continue;
      }

      $mapping[] = [
        'datasource' => $id,
        'entity_type' => 'embargo',
        'property_path_to_foreign_entity' => 'embargo:entity:id',
        'field_name' => 'id',
      ];
    }
  }

}
