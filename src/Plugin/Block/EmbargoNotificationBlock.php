<?php

namespace Drupal\embargo\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\ResettableStackedRouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\embargo\EmbargoInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

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
   * @var string
   */
  protected $adminMail;

  /**
   * The notification message.
   *
   * @var string
   */
  protected $notificationMessage;

  /**
   * A route matching interface.
   *
   * @var \Drupal\Core\Routing\ResettableStackedRouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Embargo entity storage.
   *
   * @var \Drupal\embargo\EmbargoStorageInterface
   */
  protected $storage;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $user;

  /**
   * The object renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Construct embargo notification block.
   *
   * @param array $configuration
   *   Block configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Routing\ResettableStackedRouteMatchInterface $route_match
   *   A route matching interface.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request being made to check access against.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   A configuration factory interface.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   An entity type manager.
   * @param \Drupal\Core\Session\AccountProxyInterface $user
   *   The current user.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The object renderer.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ResettableStackedRouteMatchInterface $route_match, RequestStack $request_stack, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, AccountProxyInterface $user, RendererInterface $renderer) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $settings = $config_factory->get('embargo.settings');
    $this->adminMail = $settings->get('contact_email');
    $this->notificationMessage = $settings->get('notification_message');
    $this->storage = $entity_type_manager->getStorage('embargo');
    $this->routeMatch = $route_match;
    $this->request = $request_stack->getCurrentRequest();
    $this->user = $user;
    $this->renderer = $renderer;
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
      $container->get('request_stack'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('renderer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->routeMatch->getParameter('node');
    if (!($node instanceof NodeInterface)) {
      return [];
    }
    // Displays even if the embargo is exempt in the current context.
    $applicable_embargoes = $this->storage->getApplicableEmbargoes($node);
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
  public function getCacheTags() {
    // When the given node changes (route), the block should rebuild.
    if ($node = $this->routeMatch->getParameter('node')) {
      return Cache::mergeTags(
        parent::getCacheTags(),
        $node->getCacheTags(),
      );
    }

    // Return default tags, if not on a node page.
    return parent::getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // Ensure that with every new node/route, this block will be rebuilt.
    return Cache::mergeContexts(parent::getCacheContexts(), ['route', 'url']);
  }

}
