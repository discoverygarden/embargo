<?php

namespace Drupal\embargo\Plugin\Validation\Constraint;

use Drupal\embargo\EmbargoInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the date is provided for scheduled embargo entities.
 */
class ScheduledEmbargoDateProvidedConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    /** @var \Drupal\embargo\EmbargoInterface $value */
    $scheduled = $value->getExpirationType() == EmbargoInterface::EXPIRATION_TYPE_SCHEDULED;

    // If the resolution is other, require the order number.
    if ($scheduled && is_null($value->getExpirationDate())) {
      /** @var \Drupal\embargo\Plugin\Validation\Constraint\ScheduledEmbargoDateProvidedConstraint $constraint */
      $this->context->buildViolation($constraint->dateRequired)
        ->atPath('expiration_date')
        ->addViolation();
    }
  }

}
