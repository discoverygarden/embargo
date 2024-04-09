<?php

namespace Drupal\embargo\EventSubscriber;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\search_api\Utility\FieldsHelperInterface;
use Drupal\search_api_solr\Event\PreQueryEvent;
use Drupal\search_api_solr\Event\SearchApiSolrEvents;
use Drupal\search_api_solr\SolrBackendInterface;
use Solarium\QueryType\Select\Query\Query as SolariumSelectQuery;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Embargo join processor event subscriber.
 */
class EmbargoJoinProcessorEventSubscriber implements EventSubscriberInterface, ContainerInjectionInterface {

  /**
   * Constructor.
   */
  public function __construct(
    protected FieldsHelperInterface $fieldsHelper,
    protected AccountInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RequestStack $requestStack,
  ) {
    // No-op.
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) : self {
    return new static(
      $container->get('search_api.fields_helper'),
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public static function getSubscribedEvents() {
    $events = [];

    if (class_exists(SearchApiSolrEvents::class)) {
      $events += [
        SearchApiSolrEvents::PRE_QUERY => 'preQuery',
      ];
    }

    return $events;
  }

  /**
   * Event handler; respond to search_api_solr pre-query event.
   *
   * @param \Drupal\search_api_solr\Event\PreQueryEvent $event
   *   The event to which to respond.
   */
  public function preQuery(PreQueryEvent $event) : void {
    $search_api_query = $event->getSearchApiQuery();
    if (!$search_api_query->hasTag('embargo_join_processor')) {
      return;
    }

    $queries = $search_api_query->getOption('embargo_join_processor__queries', []);

    if (!$queries) {
      return;
    }

    $backend = $search_api_query->getIndex()->getServerInstance()->getBackend();
    assert($backend instanceof SolrBackendInterface);
    $map = $backend->getSolrFieldNames($search_api_query->getIndex());
    $memoized_map = [];
    $get_field_name = function (?string $datasource_id, string $property_path) use ($search_api_query, $map, &$memoized_map) {
      $key = "{$datasource_id}__{$property_path}";
      if (!isset($memoized_map[$key])) {
        $fields = $this->fieldsHelper->filterForPropertyPath(
          $search_api_query->getIndex()->getFieldsByDatasource($datasource_id),
          $datasource_id,
          $property_path,
        );
        /** @var \Drupal\search_api\Item\FieldInterface $field */
        $field = reset($fields);

        $memoized_map[$key] = $map[$field->getFieldIdentifier()];
      }

      return $memoized_map[$key];
    };

    $solarium_query = $event->getSolariumQuery();
    assert($solarium_query instanceof SolariumSelectQuery);
    $helper = $solarium_query->getHelper();

    /** @var \Drupal\embargo\IpRangeInterface[] $ip_range_entities */
    $ip_range_entities = $search_api_query->getOption('embargo_join_processor__ip_ranges', []);

    foreach ($queries as $type => $info) {
      $solarium_query->createFilterQuery([
        'key' => "embargo_join:{$type}",
        'query' => strtr(
          implode(' ', [
            '(*:* -!datasource_field:(!datasources))',
            '(*:* -_query_:"!join*:*")',
            '_query_:"!join(',
            implode(' ', [
              '+(*:* -!type_field:\\"0\\")',
              '+!type_field:\\"1\\"',
              '+!date_field:[* TO \\"!date_value\\"]',
              '+(*:* -!date_field:[\\"!next_date_value\\" TO *])',
            ]),
            ')"',
            '!join!exempt_user_field:"!current_user"',
            $ip_range_entities ? '_query_:"!join!exempt_ip_field:(!exempt_ip_ranges)"' : '',
          ]),
          [
            '!join' => $helper->join(
              $get_field_name(NULL, $info['path']),
              $get_field_name('entity:embargo', 'embargoed_node:entity:nid'),
            ),
            '!type_field' => $get_field_name('entity:embargo', 'expiration_type'),
            '!exempt_user_field' => $get_field_name('entity:embargo', 'exempt_users:entity:uid'),
            '!current_user' => $this->currentUser->id(),
            '!exempt_ip_field' => $get_field_name('entity:embargo', 'exempt_ips:entity:id'),
            '!exempt_ip_ranges' => implode(
              ' ',
              array_map(
                $helper->escapeTerm(...),
                array_map(
                  function ($range) {
                    return $range->id();
                  },
                  $ip_range_entities
                )
              )
            ),
            '!embargo_id' => $get_field_name('entity:embargo', 'id'),
            '!date_field' => $get_field_name('entity:embargo', 'expiration_date'),
            '!date_value' => $helper->formatDate(strtotime('now')),
            '!next_date_value' => $helper->formatDate(strtotime('now + 1day')),
            '!datasource_field' => $map['search_api_datasource'],
            '!datasources' => implode(',', array_map(
              function (string $source_id) {
                return strtr('"!source"', [
                  '!source' => $source_id,
                ]);
              },
              $info['data sources'],
            )),
          ],
        ),
      ])->addTag("embargo_join_processor:{$type}");
    }

  }

}
