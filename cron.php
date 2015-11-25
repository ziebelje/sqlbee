<?php

// Recommend running this file on a cron job once per minute. Make sure the path
// to cron.php matches where you put sqlbee on your machine.
//
// Suggested crontab entry:
// * * * * * php -f /var/www/sqlbee/cron.php
require_once 'sqlbee.php';
$sqlbee = new sqlbee\sqlbee();

// Poll for the thermostat summary. The response of this determines if
// additional API calls are necessary.
$response = $sqlbee->get_thermostat_summary();

// Run this one regardless since it's not clear what revisions are used for all
// of this data.
$sqlbee->sync_thermostats();

foreach($response as $thermostat_id => $changed_revisions) {
  if(array_key_exists('runtime_revision', $changed_revisions) === true) {
    $sqlbee->sync_runtime_report($thermostat_id);
  }
}
