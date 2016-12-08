<?php

/**
 * Recommend running this file on a cron job once per minute.
 */

namespace sqlbee;
require_once 'sqlbee.php';

$sqlbee = new sqlbee();

// Poll for the thermostat summary. The response of this determines if
// additional API calls are necessary.
$response = $sqlbee->get_thermostat_summary();

// Run this one regardless since it's not clear what revisions are used for all
// of this data.
$sqlbee->sync_thermostats();

foreach($response as $thermostat_id => $changed_revisions) {
  // if(array_key_exists('runtime_revision', $changed_revisions) === true) {
    $sqlbee->sync_runtime_report($thermostat_id);
  // }
}
