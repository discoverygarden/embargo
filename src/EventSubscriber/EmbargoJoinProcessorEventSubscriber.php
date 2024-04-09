<?php

namespace Drupal\embargo\EventSubscriber;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\search_api\Query\QueryInterface;
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
    return [
      SearchApiSolrEvents::PRE_QUERY => 'preQuery',
    ];
  }

  /**
   * Event handler; respond to search_api_solr pre-query event.
   *
   * @param \Drupal\search_api_solr\Event\PreQueryEvent $event
   *   The event to which to respond.
   */
  public function preQuery(PreQueryEvent $event) : void {
    dsm('asdf');
    $search_api_query = $event->getSearchApiQuery();
    if (!$search_api_query->hasTag('embargo_join_processor')) {
      return;
    }

    $query_info = $search_api_query->getOption('embargo_join_processor');
    /** @var \Drupal\embargo\IpRangeInterface[] $ip_range_entities */
    $ip_range_entities = $query_info['ip_ranges'];

    $backend = $search_api_query->getIndex()->getServerInstance()->getBackend();
    assert($backend instanceof SolrBackendInterface);
    $map = $backend->getSolrFieldNames($search_api_query->getIndex());
    $get_field_name = function (?string $datasource_id, string $property_path) use ($search_api_query, $map) {
      $fields = $this->fieldsHelper->filterForPropertyPath(
        $search_api_query->getIndex()->getFieldsByDatasource($datasource_id),
        $datasource_id,
        $property_path,
      );
      /** @var \Drupal\search_api\Item\FieldInterface $field */
      $field = reset($fields);

      return $map[$field->getFieldIdentifier()];
    };

    $solarium_query = $event->getSolariumQuery();
    assert($solarium_query instanceof SolariumSelectQuery);
    $helper = $solarium_query->getHelper();

    foreach ($query_info['queries'] as $type => $info) {
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

    // {!tag=embargo:entity:media,embargo_schedule,embargo:entity:node,embargo_processor,embargo_access}
    // (
    //   +(
    //     (*:* -itm_id_1:[* TO *])
    //     (
    //       +(*:* -itm_expiration_type_1:"0")
    //       +itm_expiration_type_1:"1"
    //       +dm_expiration_date_1:[* TO "2024-04-05T00:00:00Z"]
    //       +(*:* -dm_expiration_date_1:["2024-04-06T00:00:00Z" TO "6596-12-04T00:00:00Z"])
    //     )
    //     itm_id_2:"1"
    //   )
    //   +(
    //     (*:* -itm_id_3:[* TO *])
    //     (
    //       +(*:* -itm_expiration_type_2:"0")
    //       +itm_expiration_type_2:"1"
    //       +dm_expiration_date_2:[* TO "2024-04-05T00:00:00Z"]
    //       +(*:* -dm_expiration_date_2:["2024-04-06T00:00:00Z" TO "6596-12-04T00:00:00Z"])
    //     )
    //     itm_id_4:"1"
    //   )
    // )


  }

}
