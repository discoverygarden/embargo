<?php

/**
 * @file
 * Post-update hooks.
 */

use Discoverygarden\UpdateHelper;

/**
 * Ensure the islandora_hierarchical_access module has been enabled.
 */
function embargo_post_update_enable_islandora_hierarchical_access() {
  UpdateHelper::ensureModuleEnabled('islandora_hierarchical_access');
}
