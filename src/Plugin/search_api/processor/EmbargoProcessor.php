<?php

namespace Drupal\embargo\Plugin\search_api\processor;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\embargo\EmbargoInterface;
use Drupal\embargo\EmbargoStorageInterface;
use Drupal\embargo\Plugin\search_api\processor\Property\EmbargoInfoProperty;
use Drupal\file\FileInterface;
use Drupal\islandora\IslandoraUtils;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A search_api_solr processor to add embargo related info.
 *
 * @SearchApiProcessor(
 *   id = "embargo_processor",
 *   label = @Translation("Embargo Processor"),
 *   description = @Translation("Processor to add information to the index related to Embargo."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = false,
 *   hidden = false,
 * )
 */
class EmbargoProcessor extends ProcessorPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Embargo entity storage.
   *
   * @var \Drupal\embargo\EmbargoStorageInterface
   */
  protected EmbargoStorageInterface $storage;

  /**
   * Islandora utils helper service.
   *
   * XXX: Ideally, this would reference an interface; however, such does not
   * exist.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected IslandoraUtils $islandoraUtils;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);

    $instance->storage = $container->get('entity_type.manager')->get('embargo');
    $instance->islandoraUtils = $container->get('islandora.utils');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) : array {
    if (!$datasource || !in_array($datasource->getEntityTypeId(), ['file', 'media', 'node'])) {
      return [];
    }

    return [
      'embargo_info' => new EmbargoInfoProperty([
        'label' => $this->t('Embargo info'),
        'description' => $this->t('Aggregated embargo info'),
        'processor_id' => $this->getPluginId(),
        'is_list' => FALSE,
        'computed' => FALSE,
      ]),
    ];
  }

  protected function getNodes(ItemInterface $item) : iterable {
    $original = $item->getOriginalObject();

    if ($original instanceof NodeInterface) {
      yield $original;
    }
    elseif ($original instanceof MediaInterface) {
      yield $this->islandoraUtils->getParentNode($original);
    }
    elseif ($original instanceof FileInterface) {
      foreach ($this->islandoraUtils->getReferencingMedia($original->id()) as $media) {
        yield $this->islandoraUtils->getParentNode($media);
      }
    }
  }

  protected function getEmbargoes(ItemInterface $item) : iterable {
    foreach ($this->getNodes($item) as $node) {
      foreach ($this->storage->getApplicableEmbargoes($node) as $embargo) {
        yield $embargo;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $info = [
      'total_count' => 0,
      'indefinite_count' => 0,
      'scheduled_timestamps' => [],
      'exempt_users' => [],
      'exempt_ip_ranges' => [],
    ];

    $source_type_id = $item->getDatasource()->getEntityTypeId();
    if (!in_array($source_type_id, ['file', 'media', 'node'])) {
      return;
    }

    // Get Embargo details and prepare to pass it to index field.
    foreach ($this->getEmbargoes($item) as $embargo) {
      if ($embargo->getEmbargoType() === EmbargoInterface::EMBARGO_TYPE_FILE && $source_type_id === 'node') {
        continue;
      }

      $info['total_count']++;
      if ($embargo->getExpirationType() === EmbargoInterface::EXPIRATION_TYPE_INDEFINITE) {
        $info['indefinite_count']++;
      }
      else {
        $info['scheduled_timestamps'][] = $embargo->getExpirationDate()->getTimestamp();
      }

      $info['exempt_users'] = array_merge(
        $info['exempt_users'],
        array_map(function (UserInterface $user) {
          return $user->id();
        }, $embargo->getExemptUsers()),
      );
      if ($range_id = $embargo->getExemptIps()?->id()) {
        $info['exempt_ip_ranges'][] = $range_id;
      }
    }

    foreach (['scheduled_timestamps', 'exempt_users', 'exempt_ip_ranges'] as $key) {
      $info[$key] = array_unique($info[$key]);
    }

    $fields = $this->getFieldsHelper()
      ->filterForPropertyPath($item->getFields(), $item->getDatasourceId(), 'embargo_info');
    foreach ($fields as $field) {
      $field->addValue($info);
    }
  }

}
