<?php

require_once 'sqlbee.php';
$sqlbee = new sqlbee();

// 30 second delay from when PIN is provided to when PIN must be authorized.
$delay = 30;

$response = $sqlbee->authorize();
echo('Enter this PIN on ecobee.com: ' . $response['ecobeePin'] . PHP_EOL);
echo('You have ' . $delay . ' seconds...' . PHP_EOL);

sleep($delay);

// This will grant sqlbee it's first access to the thermostat data and allow it
// to log in as necessary in the future.
try {
  $response = $sqlbee->grant_token($response['code']);
  echo('Authorized! Access token stored (' . $response['access_token'] . ')' . PHP_EOL);
}
catch(Exception $e) {
  echo('NOT AUTHORIZED! Please try again.' . PHP_EOL);
}
