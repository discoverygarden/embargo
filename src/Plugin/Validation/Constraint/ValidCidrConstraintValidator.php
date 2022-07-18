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
  public function validate($items, Constraint $constraint) {
    /** @var \Drupal\Core\Field\FieldItemList $items */
    /** @var ValidCidrConstraint $constraint */
    if (!$item = $items->first()) {
      return;
    }
    foreach ($items as $item) {
      $values = $item->getValue();
      foreach ($values as $value) {
        if (!IpRange::isValidCidr($value)) {
          $this->context->addViolation($constraint->message, [
            '%value' => $value,
          ]);
          break;
        }
      }
    }

  }

}
