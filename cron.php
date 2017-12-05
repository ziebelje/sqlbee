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

// Sync the runtime report if any of the revision values has changed.
foreach($response as $thermostat_id => $changed_revisions) {
  $sqlbee->sync_runtime_report($thermostat_id);
}
