<?php

namespace Drupal\embargo\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\embargo\EmbargoInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for EmbargoEntity entities.
 */
class EmbargoForm extends ContentEntityForm {

  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $currentRouteMatch;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Routing\CurrentRouteMatch $current_route_match
   *   The current route.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeBundleInfoInterface $entity_type_bundle_info, TimeInterface $time, CurrentRouteMatch $current_route_match) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->currentRouteMatch = $current_route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    // Pre-populate the node field is passed as a parameter
    // to the URL where this form is hosted.
    $node = NULL;
    $param = $this->currentRouteMatch->getParameter('node');
    if ($param instanceof NodeInterface) {
      $node = $param;
    }
    elseif (!empty($param)) {
      $node = $this->entityTypeManager->getStorage('node')->load($param);
    }
    if ($node) {
      $form['embargoed_node']['widget'][0]['target_id']['#default_value'] = $node;
    }
    // Hack since "#states" does not work with datetime form elements:
    // https://www.drupal.org/project/drupal/issues/2419131
    //
    // Required check is handled as validation constraint on the entity.
    $form['expiration_date']['#states'] = [
      'visible' => [
        ':input[name="expiration_type"]' => ['value' => EmbargoInterface::EXPIRATION_TYPE_SCHEDULED],
      ],
    ];
    // For convenience set the default date to today at midnight in the users
    // timezone.
    $element = &$form['expiration_date']['widget'][0]['value'];
    if (is_null($element['#default_value'])) {
      $element['#default_value'] = new DrupalDateTime('midnight');
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\embargo\EmbargoInterface $embargo */
    $embargo = $this->entity;
    // Clear out date if set to indefinite.
    if ($embargo->getExpirationType() === EmbargoInterface::EXPIRATION_TYPE_INDEFINITE) {
      $embargo->setExpirationDate(NULL);
    }
    $result = parent::save($form, $form_state);
    $embargo_link = $embargo->toLink($this->t('View'), 'collection')->toString();
    $node = $embargo->getEmbargoedNode();
    $node_link = $node->toLink()->toString();
    $context = ['%node' => $node_link, 'link' => $embargo_link];
    $t_args = ['%node' => $node_link];
    if ($result == SAVED_NEW) {
      $this->logger('embargoes')->notice('Embargo added for %node.', $context);
      $this->messenger()->addStatus($this->t('Embargo added for %node.', $t_args));
    }
    else {
      $this->logger('embargoes')->notice('Embargo updated for %node.', $context);
      $this->messenger()->addStatus($this->t('Embargo added for %node.', $t_args));
    }
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
  }

}
