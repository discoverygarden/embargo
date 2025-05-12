<?php

namespace Drupal\reindex_embargoes\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Entity\Index;
use Drupal\search_api\IndexInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to configure which indexes to target.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, $typedConfigManager = NULL) {
    parent::__construct($config_factory, $typedConfigManager);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
    );
  }

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

    $indexes = $this->entityTypeManager->getStorage('search_api_index')->loadMultiple();
    $options = [];

    foreach ($indexes as $index) {
      if ($index instanceof IndexInterface) {
        $options[$index->id()] = $index->label();
        if (!$index->status()) {
          $options[$index->id()] .= ' (' . $this->t('disabled') . ')';
        }
      }
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
