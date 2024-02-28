<?php

namespace Drupal\embargo\Plugin\search_api\processor\Property;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\ComplexDataDefinitionBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\search_api\Processor\ProcessorPropertyInterface;

/**
 * Embargo info property data definition.
 */
class EmbargoInfoProperty extends ComplexDataDefinitionBase implements ProcessorPropertyInterface {

  use StringTranslationTrait;

  /**
   * {@inheritDoc}
   */
  public function getPropertyDefinitions() : array {
    if (!isset($this->propertyDefinitions)) {
      $this->propertyDefinitions = [
        'total_count' => new ProcessorProperty([
          'label' => $this->t('Applicable embargo count'),
          'description' => $this->t('Total number of applicable embargoes.'),
          'type' => 'int',
          'processor_id' => $this->getProcessorId(),
          'is_list' => FALSE,
          'computed' => FALSE,
        ]),
        'indefinite_count' => new ProcessorProperty([
          'label' => $this->t('Count of indefinite embargoes'),
          'description' => $this->t('Total number of indefinite embargoes.'),
          'type' => 'int',
          'processor_id' => $this->getProcessorId(),
          'is_list' => FALSE,
          'computed' => FALSE,
        ]),
        'scheduled_timestamps' => new ProcessorProperty([
          'label' => $this->t('Schedule embargo expiry timestamps'),
          'description' => $this->t('Unix timestamps when the embargoes expire.'),
          'type' => 'int',
          'processor_id' => $this->getProcessorId(),
          'is_list' => TRUE,
          'computed' => FALSE,
        ]),
        'exempt_users' => new ProcessorProperty([
          'label' => $this->t('IDs of users exempt from embargoes'),
          'description' => '',
          'type' => 'int',
          'processor_id' => $this->getProcessorId(),
          'is_list' => TRUE,
          'computed' => FALSE,
        ]),
        'exempt_ip_ranges' => new ProcessorProperty([
          'label' => $this->t('IP range entity IDs'),
          'description' => '',
          'type' => 'string',
          'processor_id' => $this->getProcessorId(),
          'is_list' => TRUE,
          'computed' => FALSE,
        ]),
      ];
    }

    return $this->propertyDefinitions;
  }

  /**
   * {@inheritDoc}
   */
  public function getProcessorId() {
    return $this->definition['processor_id'];
  }

  /**
   * {@inheritDoc}
   */
  public function isHidden() : bool {
    return !empty($this->definition['hidden']);
  }

  /**
   * {@inheritdoc}
   */
  public function isList() : bool {
    return (bool) ($this->definition['is_list'] ?? parent::isList());
  }

}
