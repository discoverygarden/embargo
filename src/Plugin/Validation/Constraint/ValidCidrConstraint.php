<?php

namespace Drupal\embargo\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Verify that the date is not empty when scheduled.
 *
 * @Constraint(
 *   id = "ValidCidr",
 *   label = @Translation("Scheduled Embargo Date Provided", context = "Validation"),
 *   type = { "string" }
 * )
 */
class ValidCidrConstraint extends Constraint {

  /**
   * The message to display to the user on invalid condition.
   *
   * @var string
   */
  public $message = 'The value: %value, is not in a valid CIDR format for IPv4 or IPv6';

}
