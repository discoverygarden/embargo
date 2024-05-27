<?php

/**
 * @file
 * Views hook implementations.
 */

/**
 * Implements hook_views_data_alter().
 */
function embargo_views_data_alter(array &$data) {

  $data['node_field_data']['embargo__embargoes'] = [
    'title' => \t('Embargoes'),
    'help' => \t('Embargoes applicable to the given node.'),
    'relationship' => [
      'base' => 'embargo',
      'base field' => 'embargoed_node',
      'field' => 'nid',
      'id' => 'standard',
      'label' => \t('Embargoes'),
    ],
  ];
  $data['users_field_data']['embargo__exempt_users'] = [
    'title' => \t('Embargo exemptions'),
    'help' => \t('Embargoes for which the given user is specifically exempt.'),
    'relationship' => [
      'id' => 'entity_reverse',
      'field_name' => 'embargo__exempt_users',
      'entity_type' => 'embargo',
      'field table' => 'embargo__exempt_users',
      'field field' => 'exempt_users_target_id',
      'base' => 'embargo',
      'base field' => 'id',
      'label' => \t('Embargo exemptions'),
    ],
  ];

}
