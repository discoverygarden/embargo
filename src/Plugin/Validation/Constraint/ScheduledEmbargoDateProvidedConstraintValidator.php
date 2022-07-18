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
  public function validate($entity, Constraint $constraint) {
    /** @var Drupal\embargo\Entity\EmbargoInterface $entity */
    $scheduled = $entity->getExpirationType() == EmbargoInterface::EXPIRATION_TYPE_SCHEDULED;

    // If the resolution is other, require the order number.
    if ($scheduled && is_null($entity->getExpirationDate())) {
      $this->context->buildViolation($constraint->dateRequired)
        ->atPath('expiration_date')
        ->addViolation();
    }
  }

}
