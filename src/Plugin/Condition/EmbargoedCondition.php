<?php

namespace Drupal\embargo\Plugin\Condition;

use Drupal\Core\Condition\ConditionPluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\embargo\EmbargoStorageInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Condition to filter on whether or not a node is embargoed.
 *
 * @Condition(
 *   id = "embargo_embargoed_condition",
 *   label = @Translation("Node is embargoed"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", required = TRUE, label = @Translation("Node"))
 *   }
 * )
 */
class EmbargoedCondition extends ConditionPluginBase implements ContainerFactoryPluginInterface {

  /**
   * A route matching interface.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Embargo entity storage.
   *
   * @var \Drupal\embargo\EmbargoStorageInterface
   */
  protected $storage;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $user;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Create a new embargoed condition.
   *
   * @param array $configuration
   *   The condition configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param string $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   A route matching interface.
   * @param \Drupal\embargo\EmbargoStorageInterface $storage
   *   Embargo entity storage.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The current request stack.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match, EmbargoStorageInterface $storage, AccountInterface $current_user, RequestStack $request_stack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->storage = $storage;
    $this->user = $current_user;
    $this->request = $request_stack->getCurrentRequest();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('entity_type.manager')->getStorage('embargo'),
      $container->get('current_user'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['filter'] = [
      '#type' => 'radios',
      '#title' => $this->t('Filter'),
      '#default_value' => $this->configuration['filter'],
      '#description' => $this->t('Select the scope of embargo to trigger on.'),
      '#options' => [
        'off' => $this->t('Always trigger regardless of embargo status'),
        'all' => $this->t('All embargoes on node'),
        'current' => $this->t('Current embargoes on node (ignore expired)'),
        'active' => $this->t('Active embargoes on node (ignore bypassed)'),
      ],
    ];
    return parent::buildConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configuration['filter'] = $form_state->getValue('filter');
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['filter' => 'off'] + parent::defaultConfiguration();
  }

  /**
   * Evaluates the condition and returns TRUE or FALSE accordingly.
   *
   * @return bool
   *   TRUE if the condition has been met, FALSE otherwise.
   */
  public function evaluate() {
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      switch ($this->configuration['filter']) {
        case 'off':
          $embargoed = TRUE;
          break;

        case 'all':
          $embargoes = $this->storage->getApplicableEmbargoes($node);
          $embargoed = count($embargoes) > 0;
          break;

        case 'current':
          $now = time();
          $embargoes = $this->storage->getApplicableEmbargoes($node);
          $current = array_filter($embargoes, function ($embargo) use ($now) {
            return $embargo->expiresBefore($now) === FALSE;
          });
          $embargoed = count($current) > 0;
          break;

        case 'active':
          $active = $this->storage->getApplicableNonExemptNonExpiredEmbargoes($node);
          $embargoed = count($active) > 0;
          break;
      }

    }
    else {
      $embargoed = FALSE;
    }

    return $embargoed;
  }

  /**
   * Provides a human readable summary of the condition's configuration.
   */
  public function summary() {
  }

}
