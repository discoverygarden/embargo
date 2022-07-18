<?php

namespace Drupal\embargo\Plugin\Validation\Constraint;

use Drupal\Core\Entity\Plugin\Validation\Constraint\CompositeConstraintBase;

/**
 * Verify that the date is not empty when scheduled.
 *
 * @Constraint(
 *   id = "ScheduledEmbargoDateProvided",
 *   label = @Translation("Scheduled Embargo Date Provided", context = "Validation"),
 *   type = "entity:embargo"
 * )
 */
class ScheduledEmbargoDateProvidedConstraint extends CompositeConstraintBase {

  /**
   * A message to display when the constraint is violated.
   *
   * @var string
   */
  public $dateRequired = 'The date is required when an embargo is scheduled.';

  /**
   * {@inheritdoc}
   */
  public function coversFields() {
    return ['expiration_type', 'expiration_date'];
  }

}
