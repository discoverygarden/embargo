<?php

namespace Drupal\embargo\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\ResettableStackedRouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\embargo\EmbargoInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a "Embargo Notifications" block.
 *
 * @Block(
 *   id="embargo_notification_block",
 *   admin_label = @Translation("Embargo Notifications"),
 *   category = @Translation("Embargo")
 * )
 */
class EmbargoNotificationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The admin email address.
   *
   * @var string|null
   */
  protected ?string $adminMail;

  /**
   * The notification message.
   *
   * @var string
   */
  protected string $notificationMessage;

  /**
   * A route matching interface.
   *
   * @var \Drupal\Core\Routing\ResettableStackedRouteMatchInterface
   */
  protected ResettableStackedRouteMatchInterface $routeMatch;

  /**
   * The request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected Request $request;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $user;

  /**
   * The object renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * Drupal's entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) : self {
    $instance = new static($configuration, $plugin_id, $plugin_definition);

    $settings = $container->get('config.factory')->get('embargo.settings');
    $instance->adminMail = $settings->get('contact_email');
    $instance->notificationMessage = $settings->get('notification_message');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->routeMatch = $container->get('current_route_match');
    $instance->request = $container->get('request_stack')->getCurrentRequest();
    $instance->user = $container->get('current_user');
    $instance->renderer = $container->get('renderer');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function build() : array {
    if (!($node = $this->getNode())) {
      return [];
    }
    // Displays even if the embargo is exempt in the current context.
    /** @var \Drupal\embargo\EmbargoStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('embargo');
    $applicable_embargoes = $storage->getApplicableEmbargoes($node);
    if (empty($applicable_embargoes)) {
      return [];
    }
    $now = $this->request->server->get('REQUEST_TIME');
    $ip = $this->request->getClientIp();
    $embargoes = [];
    foreach ($applicable_embargoes as $embargo) {
      $id = Html::getUniqueId('embargo_notification');
      $expired = $embargo->expiresBefore($now);
      $exempt_user = $embargo->isUserExempt($this->user);
      $exempt_ip = $embargo->ipIsExempt($ip);

      $embargoes[$id] = [
        'actual' => $embargo,
        'indefinite' => $embargo->getExpirationType() === EmbargoInterface::EXPIRATION_TYPE_INDEFINITE,
        'expired' => $expired,
        'exempt_user' => $exempt_user,
        'exempt_ip' => $exempt_ip,
        'exempt' => $expired || $exempt_user || $exempt_ip,
        'type' => $embargo->getEmbargoTypeLabel(),
        'embargo_type' => $embargo->embargo_type->view('default'),
        'expiration_type' => $embargo->expiration_type->view('default'),
        'expiration_date' => $embargo->expiration_date->view([
          'type' => 'datetime_time_ago',
          'label' => 'inline',
        ]),
        'exempt_ips' => $embargo->exempt_ips->view('default'),
        'exempt_users' => $embargo->exempt_users->view('default'),
        'additional_emails' => $embargo->additional_emails->view('default'),
      ];
    }

    $build = [
      '#theme' => 'embargo_notification',
      '#message' => $this->t($this->notificationMessage, ['@contact' => $this->adminMail]), // phpcs:ignore
      '#embargoes' => $embargoes,
    ];
    $this->renderer->addCacheableDependency($build, $node);
    foreach ($embargoes as $embargo) {
      $this->renderer->addCacheableDependency($build, $embargo);
    }
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() : array {
    $tags = parent::getCacheTags();

    // When the given node changes (route), the block should rebuild.
    if ($node = $this->getNode()) {
      $tags = Cache::mergeTags(
        $tags,
        $node->getCacheTags(),
      );
    }

    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() : array {
    $contexts = Cache::mergeContexts(
      parent::getCacheContexts(),
      // Ensure that with every new node/route, this block will be rebuilt.
      [
        'route',
        'url',
      ],
    );

    if ($node = $this->getNode()) {
      $contexts = Cache::mergeContexts(
        $contexts,
        $node->getCacheContexts(),
      );
    }

    return $contexts;
  }

  /**
   * Helper; get the active node.
   *
   * @return \Drupal\node\NodeInterface|null
   *   Get the active node.
   */
  protected function getNode() : ?NodeInterface {
    $node_candidate = $this->routeMatch->getParameter('node');
    return $node_candidate instanceof NodeInterface ?
      $node_candidate :
      NULL;
  }

}
