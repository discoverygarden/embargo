<?php

namespace Drupal\embargo\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for setting settings for the Embargoes module.
 */
class EmbargoSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'embargo_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['embargo.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('embargo.settings');

    $form['contact_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Contact Email'),
      '#description' => $this->t('Email address for who should be contacted in case users have questions about access.'),
      '#default_value' => $config->get('contact_email'),
    ];

    $form['notification_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Embargo notification message.'),
      '#description' => $this->t('Notification text displayed to the user when an object or its files are under embargo. Use the "@contact" string to include the configured contact email, if available.'),
      '#default_value' => $config->get('notification_message'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('embargo.settings');
    $config->set('contact_email', $form_state->getValue('contact_email'));
    $config->set('notification_message', $form_state->getValue('notification_message'));
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
