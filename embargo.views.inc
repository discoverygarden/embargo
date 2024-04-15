<?php

/**
 * @file
 * Views hook implementations.
 */

/**
 * Implements hook_views_data_alter().
 */
function embargo_views_data_alter(array &$data) {

  $data['node_field_data']['embargoed_node'] = [
    'title' => \t('Embargoed Nodee'),
    'relationship' => [
      'base' => 'embargo',
      'base field' => 'embargoed_node',
      'field' => 'nid',
      'id' => 'standard',
      'label' => \t('Embargoed Nodee'),
    ],
  ];

}
