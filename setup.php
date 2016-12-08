<?php

namespace sqlbee;
require_once 'sqlbee.php';

$sqlbee = new sqlbee();
$response = $sqlbee->authorize();

echo PHP_EOL;
echo ' ┌──────────────────────────────────────┐' . PHP_EOL;
echo ' │               _ _                    │' . PHP_EOL;
echo ' │              | | |                   │' . PHP_EOL;
echo ' │     ___  ____| | |__   ___  ___      │' . PHP_EOL;
echo ' │    / __|/ _  | |  _ \ / _ \/ _ \     │' . PHP_EOL;
echo ' │    \__ \ (_| | | |_) |  __/  __/     │' . PHP_EOL;
echo ' │    |___/\__  |_|____/ \___|\___|     │' . PHP_EOL;
echo ' │            | |                       │' . PHP_EOL;
echo ' │            |_|                       │' . PHP_EOL;
echo ' ├──────────────────────────────────────┤' . PHP_EOL;
echo ' │                                      │' . PHP_EOL;
echo ' │  1. Open ecobee.com and go to        │' . PHP_EOL;
echo ' │     My Apps > Add Application        │' . PHP_EOL;
echo ' │                                      │' . PHP_EOL;
echo ' │  2. Enter your PIN                   │' . PHP_EOL;
echo ' │                                      │' . PHP_EOL;
echo ' │             ┌──────────┐             │' . PHP_EOL;
echo ' │             │   ' . strtoupper($response['ecobeePin']) . '   │             │' . PHP_EOL;
echo ' │             └──────────┘             │' . PHP_EOL;
echo ' │                                      │' . PHP_EOL;
echo ' │      Waiting for authorization       │' . PHP_EOL;

$progress_bar = '';
echo ' │    □□□□□□□□□□□□□□□□□□□□□□□□□□□□□□    │';
for($i = 0; $i < 35; $i++) {
  echo chr(8);
}

$authorized = false;

$try_for = 60;
$seconds_left = $try_for;
$delay = 2;
while(($seconds_left -= $delay) >= 0 && $authorized === false) {
  try {
    echo '■';

    // Throws an exception if it fails
    $response = $sqlbee->grant_token($response['code']);

    $authorized = true;
  }
  catch(\Exception $e) {}

  sleep($delay);
}
echo PHP_EOL;

if($authorized === true) {
  echo ' │             👍 SUCCESS! 👍             │' . PHP_EOL;
  echo ' │                                      │' . PHP_EOL;
  echo ' │  3. Syncing...                       │' . PHP_EOL;

  // Sync over the thermostats
  $response = $sqlbee->get_thermostat_summary();
  $sqlbee->sync_thermostats();

  // Sneak in a setup variable just for this.
  $class = new \ReflectionClass('\sqlbee\configuration');
  $class->setStaticPropertyValue('setup', true);

  foreach($response as $thermostat_id => $changed_revisions) {
    $sqlbee->sync_runtime_report($thermostat_id);
  }
  sleep(1);
  echo "\r";
  echo ' │     ✓ Done                           │';
  sleep (1);
  echo PHP_EOL;

  echo ' │                                      │' . PHP_EOL;
  echo ' │  4. Dont forget to add a cron job    │' . PHP_EOL;
  echo ' │     for cron.php to keep your data   │' . PHP_EOL;
  echo ' │     up to date.                      │' . PHP_EOL;
}
else {
  echo ' │             👎 FAILURE! 👎             │' . PHP_EOL;
}

echo ' │                                      │' . PHP_EOL;
echo ' └──────────────────────────────────────┘' . PHP_EOL;
