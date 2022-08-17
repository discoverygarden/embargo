<?php

namespace Drupal\embargo\Plugin\Validation\Constraint;

use Drupal\embargo\Entity\IpRange;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the date is provided for scheduled embargo entities.
 */
class ValidCidrConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    /** @var \Drupal\Core\Field\FieldItemList $value */
    /** @var ValidCidrConstraint $constraint */
    if (!$item = $value->first()) {
      return;
    }
    foreach ($value as $item) {
      $values = $item->getValue();
      foreach ($values as $val) {
        if (!IpRange::isValidCidr($val)) {
          $this->context->addViolation($constraint->message, [
            '%value' => $val,
          ]);
          break;
        }
      }
    }

  }

}
