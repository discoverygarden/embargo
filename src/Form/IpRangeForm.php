<?php

namespace Drupal\embargo\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for EmbargoEntity entities.
 */
class IpRangeForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $result = parent::save($form, $form_state);
    /** @var \Drupal\embargo\Entity\IpRange $ip_range */
    $ip_range = $this->entity;
    $label = $ip_range->label();
    $link = $ip_range->toLink($this->t('View'))->toString();
    $context = ['%label' => $label, 'link' => $link];
    $t_args = ['%label' => $label];
    if ($result == SAVED_NEW) {
      $this->logger('embargoes')->notice('IP Range %label added.', $context);
      $this->messenger()->addStatus($this->t('IP Range %label added.', $t_args));
    }
    else {
      $this->logger('embargoes')->notice('IP Range %label updated.', $context);
      $this->messenger()->addStatus($this->t('IP Range %label updated.', $t_args));
    }
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
