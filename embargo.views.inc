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

}
