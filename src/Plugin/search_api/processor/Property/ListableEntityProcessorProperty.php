<?php

namespace Drupal\embargo\Plugin\search_api\processor\Property;

use Drupal\search_api\Processor\EntityProcessorProperty;

/**
 * Extended EntityProcessorProperty class with some additional setters.
 */
class ListableEntityProcessorProperty extends EntityProcessorProperty {

  /**
   * Set to represent a list.
   *
   * @param bool $value
   *   The value to set. Defaults to true.
   */
  public function setList(bool $value = TRUE) : self {
    $this->definition['is_list'] = $value;
    return $this;
  }

  /**
   * Set the processor ID.
   *
   * @param string $processor_id
   *   The processor ID to set.
   */
  public function setProcessorId(string $processor_id) : self {
    $this->definition['processor_id'] = $processor_id;
    return $this;
  }

}
