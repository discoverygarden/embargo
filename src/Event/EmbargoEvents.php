<?php

namespace Drupal\embargo\Event;

/**
 * Enumerate our event types.
 */
final class EmbargoEvents {

  const TAG_INCLUSION = TagInclusionEvent::class;

  const TAG_EXCLUSION = TagExclusionEvent::class;

}
