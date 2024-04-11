<?php

namespace Drupal\embargo\Event;

/**
 * Query tagging exclusion event.
 */
class TagExclusionEvent extends AbstractTagEvent {

  /**
   * Get the "unexpired" embargo alias.
   *
   * @return string
   *   The table alias.
   */
  public function getUnexpiredAlias() : string {
    return $this->query->getMetaData('embargo_unexpired_alias');
  }

}
