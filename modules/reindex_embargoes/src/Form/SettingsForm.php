<?php

namespace Drupal\reindex_embargoes\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Entity\Index;

class SettingsForm extends ConfigFormBase {
    /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return 'reindex_embargoes_settings';
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames() {
        return [
          'reindex_embargoes.settings',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state) {
        $config = $this->config('reindex_embargoes.settings');

        $indexes = Index::loadMultiple();
        $options = [];
        foreach ($indexes as $index) {
            $options[$index->id()] = $index->label();
        }

        $form['selected_indexes'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Selected indexes'),
          '#options' => $options,
          '#default_value' => $config->get('selected_indexes') ?? [],
          '#description' => $this->t('Select the indexes to be reindexed.'),
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state) {
      parent::submitForm($form, $form_state);
      $selected_indexes = $form_state->getValue('selected_indexes');
      $selected_indexes = array_keys(array_filter($selected_indexes));
      $this->config('reindex_embargoes.settings')
        ->set('selected_indexes', $selected_indexes)
        ->save();
    }
}
