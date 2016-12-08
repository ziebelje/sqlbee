<?php

namespace sqlbee;
require_once 'configuration.php';

class sqlbee {

  private $mysqli;

  /**
   * Constructor; create the database connection and start a transaction.
   */
  public function __construct() {
    $this->mysqli = new \mysqli(
      configuration::$database_host,
      configuration::$database_username,
      configuration::$database_password,
      configuration::$database_name
    );

    if ($this->mysqli->connect_error !== null) {
      throw new \Exception($this->mysqli->connect_error . '(' . $this->mysqli->connect_errno . ')');
    }

    $this->mysqli->query('start transaction') or die($this->mysqli->error);
  }

  /**
   * Commit the open transaction.
   */
  public function __destruct() {
    $this->mysqli->query('commit') or die($this->mysqli->error);
  }

  /**
   * Send an API call to ecobee and return the response.
   *
   * @param string $method GET or POST
   * @param string $endpoint The API endpoint
   * @param array $arguments POST or GET parameters
   * @param boolean $auto_refresh_token Whether or not to automatically get a
   * new token if the old one is expired.
   *
   * @return array The response of this API call.
   */
  private function ecobee($method, $endpoint, $arguments, $auto_refresh_token = true) {
    $curl_handle = curl_init();

    // Attach the client_id to all requests.
    $arguments['client_id'] = configuration::$client_id;

    // Authorize/token endpoints don't use the /1/ in the URL. Everything else
    // does.
    if($endpoint !== 'authorize' && $endpoint !== 'token') {
      $endpoint = '/1/' . $endpoint;

      // For non-authorization endpoints, add the access_token header.
      $query = 'select * from token order by token_id desc limit 1';
      $result = $this->mysqli->query($query) or die($this->mysqli->error);
      $token = $result->fetch_assoc();
      curl_setopt($curl_handle, CURLOPT_HTTPHEADER , array(
        'Authorization: Bearer ' . $token['access_token']
      ));
    }
    else {
      $endpoint = '/' . $endpoint;
    }
    $url = 'https://api.ecobee.com' . $endpoint;

    if($method === 'GET') {
      $url .= '?' . http_build_query($arguments);
    }

    curl_setopt($curl_handle, CURLOPT_URL, $url);
    curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);

    if($method === 'POST') {
      curl_setopt($curl_handle, CURLOPT_POST, true);
      curl_setopt($curl_handle, CURLOPT_POSTFIELDS, http_build_query($arguments));
    }

    $curl_response = curl_exec($curl_handle);

    if(configuration::$debug === true) {
      // TODO: Look for response and if invalid throw exception
      echo json_encode($arguments);
      echo PHP_EOL . '-----------------' . PHP_EOL;
      echo join('', array_map('trim', explode("\n", $curl_response)));
    }

    // Log this request and response
    if(configuration::$log_api_calls === true) {
      $query = '
        insert into api_log(
          `method`,
          `endpoint`,
          `json_arguments`,
          `response`
        )
        values(
          "' . $this->mysqli->real_escape_string($method) . '",
          "' . $this->mysqli->real_escape_string($endpoint) . '",
          "' . $this->mysqli->real_escape_string(json_encode($arguments)) . '",
          "' . $this->mysqli->real_escape_string($curl_response) . '"
        )
      ';
      $this->mysqli->query($query) or die($this->mysqli->error);
    }

    if($curl_response === false || curl_errno($curl_handle) !== 0) {
      throw new \Exception('cURL error: ' . curl_error($curl_handle));
    }

    $response = json_decode($curl_response, true);
    if($response === false) {
      throw new \Exception('Invalid JSON');
    }

    curl_close($curl_handle);

    // If the token was expired, refresh it and try again. Trying again sets
    // auto_refresh_token to false to prevent accidental infinite refreshing if
    // something bad happens.
    if(isset($response['status']) === true && $response['status']['code'] === 14) {
      $this->refresh_token($token['refresh_token']);
      return $this->ecobee($method, $endpoint, $arguments, false);
    }
    else {
      return $response;
    }
  }

  /**
   * Perform the first-time authorization for this app.
   *
   * @see https://www.ecobee.com/home/developer/api/documentation/v1/auth/pin-api-authorization.shtml
   *
   * @return array The response of this API call. Included will be the code
   * needed for the grant_token API call.
   */
  public function authorize() {
    return $this->ecobee(
      'GET',
      'authorize',
      array(
        'response_type' => 'ecobeePin',
        'scope' => configuration::$scope
      )
    );
  }

  /**
   * Given a code returned by the authorize endpoint, obtain an access token
   * for use on all future API calls.
   *
   * @see https://www.ecobee.com/home/developer/api/documentation/v1/auth/auth-req-resp.shtml
   * @see https://www.ecobee.com/home/developer/api/documentation/v1/auth/authz-code-authorization.shtml
   *
   * @param string $code
   *
   * @return array The response of the API call. Included will be the
   * access_token needed for the API call and the refresh_token to use if the
   * access_token ends up being expired.
   */
  public function grant_token($code) {
    $response = $this->ecobee(
      'POST',
      'token',
      array(
        'grant_type' => 'ecobeePin',
        'code' => $code
      )
    );

    if(isset($response['access_token']) === false || isset($response['refresh_token']) === false) {
      throw new \Exception('Could not grant token');
    }

    $access_token_escaped = $this->mysqli->real_escape_string($response['access_token']);
    $refresh_token_escaped = $this->mysqli->real_escape_string($response['refresh_token']);
    $query = '
      insert into token(
        `access_token`,
        `refresh_token`
      ) values(
        "' . $access_token_escaped . '",
        "' . $refresh_token_escaped . '"
      )';
    $this->mysqli->query($query) or die($this->mysqli->error);

    return $response;
  }

  /**
   * Given the latest refresh token, obtain a fresh access token for use in
   * all future API calls.
   *
   * @param string $refresh_token
   *
   * @return array The response of the API call.
   */
  public function refresh_token($refresh_token) {
    $response = $this->ecobee(
      'POST',
      'token',
      array(
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh_token
      )
    );

    if(isset($response['access_token']) === false || isset($response['refresh_token']) === false) {
      throw new \Exception('Could not grant token');
    }

    $access_token_escaped = $this->mysqli->real_escape_string($response['access_token']);
    $refresh_token_escaped = $this->mysqli->real_escape_string($response['refresh_token']);
    $query = 'insert into token(`access_token`, `refresh_token`) values("' . $access_token_escaped . '", "' . $refresh_token_escaped . '")';
    $this->mysqli->query($query) or die($this->mysqli->error);

    return $response;
  }

  /**
   * This is the main polling function and can be called fairly frequently.
   * This will get a list of all thermostats and their revisions, then return
   * any revision value that has changed so that other API calls can be made.
   *
   * @see https://www.ecobee.com/home/developer/api/documentation/v1/operations/get-thermostat-summary.shtml
   *
   * @return array An array of thermostat identifiers pointing at an array of
   * revision columns that changed.
   */
  public function get_thermostat_summary() {
    $response = $this->ecobee(
      'GET',
      'thermostatSummary',
      array(
        'body' => json_encode(array(
          'selection' => array(
            'selectionType' => 'registered',
            'selectionMatch' => ''
          )
        ))
      )
    );

    $return = array();

    // Mark all thermostats as deleted
    $query = 'update thermostat set deleted = 1';
    $this->mysqli->query($query) or die($this->mysqli->error);

    // Update revisions and a few other columns. Also create/delete new/old
    // thermostats on the fly.
    foreach($response['revisionList'] as $thermostat) {
      // Mutate the return data into what is essentially the essence of a
      // thermostat.
      $thermostat = explode(':', $thermostat);
      $thermostat = array(
        'identifier' => $thermostat[0],
        'name' => $thermostat[1],
        'connected' => $thermostat[2] === 'true' ? '1' : '0',
        'thermostat_revision' => $thermostat[3],
        'alert_revision' => $thermostat[4],
        'runtime_revision' => $thermostat[5],
        'internal_revision' => $thermostat[6]
      );

      // Check to see if this thermostat already exists.
      $query = 'select * from thermostat where identifier = "' . $this->mysqli->real_escape_string($thermostat['identifier']) . '"';
      $result = $this->mysqli->query($query) or die($this->mysqli->error);

      // If this thermostat does not already exist, create it.
      if($result->num_rows === 0) {
        $original_thermostat = array();
        $query = '
          insert into thermostat(
            identifier,
            name,
            connected,
            thermostat_revision,
            alert_revision,
            runtime_revision,
            internal_revision
          )
          values(
            "' . $this->mysqli->real_escape_string($thermostat['identifier']) . '",
            "' . $this->mysqli->real_escape_string($thermostat['name']) . '",
            "' . $this->mysqli->real_escape_string($thermostat['connected']) . '",
            "' . $this->mysqli->real_escape_string($thermostat['thermostat_revision']) . '",
            "' . $this->mysqli->real_escape_string($thermostat['alert_revision']) . '",
            "' . $this->mysqli->real_escape_string($thermostat['runtime_revision']) . '",
            "' . $this->mysqli->real_escape_string($thermostat['internal_revision']) . '"
          )';
        $result = $this->mysqli->query($query) or die($this->mysqli->error);
        $thermostat_id = $this->mysqli->insert_id;
      }
      else {
        // If this thermostat already exists, update it.
        $original_thermostat = $result->fetch_assoc();
        $query = '
          update thermostat set
            name = "' . $this->mysqli->real_escape_string($thermostat['name']) . '",
            connected = "' . $this->mysqli->real_escape_string($thermostat['connected']) . '",
            thermostat_revision = "' . $this->mysqli->real_escape_string($thermostat['thermostat_revision']) . '",
            alert_revision = "' . $this->mysqli->real_escape_string($thermostat['alert_revision']) . '",
            runtime_revision = "' . $this->mysqli->real_escape_string($thermostat['runtime_revision']) . '",
            internal_revision = "' . $this->mysqli->real_escape_string($thermostat['internal_revision']) . '",
            deleted = 0
          where
            identifier = "' . $thermostat['identifier'] . '"';
        $result = $this->mysqli->query($query) or die($this->mysqli->error);
        $thermostat_id = $original_thermostat['thermostat_id'];
      }
      $diff = array_diff($thermostat, $original_thermostat);
      $return[$thermostat_id] = array_intersect_key($diff, array_flip(array('thermostat_revision', 'alert_revision', 'runtime_revision', 'internal_revision')));
    }

    // Return the most recent values for any revision columns that have changed.
    // If it's a new thermostat, all of them will be returned. Keyed by
    // thermostat_id.
    return $return;
  }

  /**
   * Given a list of thermostats, get and update the runtime data in the
   * thermostat table.
   *
   * @see https://www.ecobee.com/home/developer/api/documentation/v1/operations/get-thermostats.shtml
   * @see https://www.ecobee.com/home/developer/api/documentation/v1/objects/Selection.shtml
   */
  public function sync_thermostats() {
    $response = $this->ecobee(
      'GET',
      'thermostat',
      array(
        'body' => json_encode(array(
          'selection' => array(
            'selectionType' => 'registered',
            'selectionMatch' => '',
            'includeRuntime' => true,
            'includeExtendedRuntime' => true,
            'includeElectricity' => true,
            'includeSettings' => true,
            'includeLocation' => true,
            'includeProgram' => true,
            'includeEvents' => true,
            'includeDevice' => true,
            'includeTechnician' => true,
            'includeUtility' => true,
            'includeManagement' => true,
            'includeAlerts' => true,
            'includeWeather' => true,
            'includeHouseDetails' => true,
            'includeOemCfg' => true,
            'includeEquipmentStatus' => true,
            'includeNotificationSettings' => true,
            'includeVersion' => true,
            'includeSensors' => true
          )
        ))
      )
    );

    // Update each thermostat with the actual and desired values.
    foreach($response['thermostatList'] as $thermostat) {
      $runtime = json_encode($thermostat['runtime']);
      $weather = json_encode($thermostat['weather']);
      $equipment_status = trim($thermostat['equipmentStatus']) !== '' ? json_encode(explode(',', $thermostat['equipmentStatus'])) : json_encode(array());
      $program = json_encode($thermostat['program']);
      $settings = json_encode($thermostat['settings']);

      $query = '
        update
          thermostat
        set
          json_runtime = "' . $this->mysqli->real_escape_string(json_encode($thermostat['runtime'])) . '",
          json_extended_runtime = "' . $this->mysqli->real_escape_string(json_encode($thermostat['extendedRuntime'])) . '",
          json_electricity = "' . $this->mysqli->real_escape_string(json_encode($thermostat['electricity'])) . '",
          json_settings = "' . $this->mysqli->real_escape_string(json_encode($thermostat['settings'])) . '",
          json_location = "' . $this->mysqli->real_escape_string(json_encode($thermostat['location'])) . '",
          json_program = "' . $this->mysqli->real_escape_string(json_encode($thermostat['program'])) . '",
          json_events = "' . $this->mysqli->real_escape_string(json_encode($thermostat['events'])) . '",
          json_device = "' . $this->mysqli->real_escape_string(json_encode($thermostat['devices'])) . '",
          json_technician = "' . $this->mysqli->real_escape_string(json_encode($thermostat['technician'])) . '",
          json_utility = "' . $this->mysqli->real_escape_string(json_encode($thermostat['utility'])) . '",
          json_management = "' . $this->mysqli->real_escape_string(json_encode($thermostat['management'])) . '",
          json_alerts = "' . $this->mysqli->real_escape_string(json_encode($thermostat['alerts'])) . '",
          json_weather = "' . $this->mysqli->real_escape_string(json_encode($thermostat['weather'])) . '",
          json_house_details = "' . $this->mysqli->real_escape_string(json_encode($thermostat['houseDetails'])) . '",
          json_oem_cfg = "' . $this->mysqli->real_escape_string(json_encode($thermostat['oemCfg'])) . '",
          json_equipment_status = "' . $this->mysqli->real_escape_string(trim($thermostat['equipmentStatus']) !== '' ? json_encode(explode(',', $thermostat['equipmentStatus'])) : json_encode(array())) . '",
          json_notification_settings = "' . $this->mysqli->real_escape_string(json_encode($thermostat['notificationSettings'])) . '",
          json_version = "' . $this->mysqli->real_escape_string(json_encode($thermostat['version'])) . '",
          json_remote_sensors = "' . $this->mysqli->real_escape_string(json_encode($thermostat['remoteSensors'])) . '"
        where
          identifier = "' . $thermostat['identifier'] . '"
      ';
      $this->mysqli->query($query) or die($this->mysqli->error);
    }
  }

  /**
   * Generates properly chunked time ranges and then syncs up to one week of
   * data at a time back to either the last entry in the table or the date the
   * thermostat was first connected.
   *
   * @param int $thermostat_id
   */
  public function sync_runtime_report($thermostat_id) {
    $thermostat_id_escaped = $this->mysqli->real_escape_string($thermostat_id);
    $query = '
      select
        *
      from
        runtime_report
      where
        thermostat_id = "' . $thermostat_id_escaped . '"
      order by
        timestamp desc
      limit 1
    ';
    $result = $this->mysqli->query($query) or die($this->mysqli->error);
    if($result->num_rows === 0) {
      $thermostat = $this->get_thermostat($thermostat_id);
      $desired_begin_gmt = strtotime($thermostat['json_runtime']['firstConnected']);
    }
    else {
      $row = $result->fetch_assoc();
      $desired_begin_gmt = strtotime($row['timestamp']) - date('Z') - (3600 * 2);
    }

    // Set $end_gmt to the current time.
    $end_gmt = time() - date('Z');

    $chunk_size = 86400 * 7; // 7 days (in seconds)

    // Start $begin_gmt at $end_gmt. In the loop, $begin_gmt is always
    // decremented by $chunk_size (no older than $desired_begin_gmt) prior to
    // calling sync_runtime_report_. Also, $end_gmt gets initially set to
    // $begin_gmt every loop to continually shift back both points.
    $begin_gmt = $end_gmt;
    do {
      $end_gmt = $begin_gmt;
      $begin_gmt = max($desired_begin_gmt, $begin_gmt - $chunk_size);
      $this->sync_runtime_report_($thermostat_id, $begin_gmt, $end_gmt);
    } while($begin_gmt > $desired_begin_gmt);
  }

  /**
   * Get the runtime report data for a specified thermostat. Updates the
   * runtime_report table.
   *
   * @param int $thermostat_id
   */
  private function sync_runtime_report_($thermostat_id, $begin_gmt, $end_gmt) {
    $columns = array(
      'auxHeat1' => 'auxiliary_heat_1',
      'auxHeat2' => 'auxiliary_heat_2',
      'auxHeat3' => 'auxiliary_heat_3',
      'compCool1' => 'compressor_cool_1',
      'compCool2' => 'compressor_cool_2',
      'compHeat1' => 'compressor_heat_1',
      'compHeat2' => 'compressor_heat_2',
      'dehumidifier' => 'dehumidifier',
      'dmOffset' => 'demand_management_offset',
      'economizer' => 'economizer',
      'fan' => 'fan',
      'humidifier' => 'humidifier',
      'outdoorHumidity' => 'outdoor_humidity',
      'outdoorTemp' => 'outdoor_temperature',
      'sky' => 'sky',
      'ventilator' => 'ventilator',
      'wind' => 'wind',
      'zoneAveTemp' => 'zone_average_temperature',
      'zoneCalendarEvent' => 'zone_calendar_event',
      'zoneCoolTemp' => 'zone_cool_temperature',
      'zoneHeatTemp' => 'zone_heat_temperature',
      'zoneHumidity' => 'zone_humidity',
      'zoneHumidityHigh' => 'zone_humidity_high',
      'zoneHumidityLow' => 'zone_humidity_low',
      'zoneHvacMode' => 'zone_hvac_mode',
      'zoneOccupancy' => 'zone_occupancy',
    );

    $thermostat = $this->get_thermostat($thermostat_id);

    $begin_date = date('Y-m-d', $begin_gmt);
    $begin_interval = date('H', $begin_gmt) * 12 + round(date('i', $begin_gmt) / 5);

    $end_date = date('Y-m-d', $end_gmt);
    $end_interval = date('H', $end_gmt) * 12 + round(date('i', $end_gmt) / 5);

    if(configuration::$setup === true) {
      echo "\r";
      $string = date('m/d/Y', $begin_gmt) . ' > ' . date('m/d/Y', $end_gmt);
      echo ' │     ' . $string;
      for($i = 0; $i < 33 - strlen($string); $i++) {
        echo ' ';
      }
      echo '│';
    }

    $response = $this->ecobee(
      'GET',
      'runtimeReport',
      array(
        'body' => json_encode(array(
          'selection' => array(
            'selectionType' => 'thermostats',
            'selectionMatch' => $thermostat['identifier'] // This is required by this API call
          ),
          'startDate' => $begin_date,
          'startInterval' => $begin_interval,
          'endDate' => $end_date,
          'endInterval' => $end_interval,
          'columns' => implode(',', array_keys($columns))
        ))
      )
    );

    $inserts = array();
    $on_duplicate_keys = array();
    foreach($response['reportList'][0]['rowList'] as $row) {
      $row = substr($row, 0, -1); // Strip the trailing comma from the array.
      $row = explode(',', $row);
      $row = array_map('trim', $row);
      $row = array_map(array($this->mysqli, 'real_escape_string'), $row);

      // Date and time are first two columns
      list($date, $time) = array_splice($row, 0, 2);
      array_unshift($row, $thermostat_id, date('Y-m-d H:i:s', strtotime($date . ' ' . $time)));

      $insert = '("' . implode('","', $row) . '")';
      $insert = str_replace('""', 'null', $insert);
      $inserts[] = $insert;
    }

    foreach(array_merge(array('thermostat_id' => 'thermostat_id', 'timestamp' => 'timestamp'), $columns) as $column) {
      $on_duplicate_keys[] = '`' . $column . '` = values(`' . $column . '`)';
    }

    $query = 'insert into runtime_report(`' . implode('`,`', array_merge(array('thermostat_id', 'timestamp'), array_values($columns))) . '`) values' . implode(',', $inserts) . ' on duplicate key update ' . implode(',', $on_duplicate_keys);
    $this->mysqli->query($query) or die($this->mysqli->error);
  }

  /**
   * Get a thermostat from a thermostat_id.
   *
   * @param int $thermostat_id
   *
   * @return array The thermostat.
   */
  private function get_thermostat($thermostat_id) {
    $query = 'select * from thermostat where thermostat_id = "' . $this->mysqli->real_escape_string($thermostat_id) . '"';
    $result = $this->mysqli->query($query) or die($this->mysqli->error);
    if($result->num_rows === 0) {
      throw new \Exception('Invalid thermostat_id');
    }
    else {
      $thermostat = $result->fetch_assoc();
      foreach($thermostat as $key => &$value) {
        if(substr($key, 0, 5) === 'json_') {
          $value = json_decode($value, true);
        }
      }
      return $thermostat;
    }
  }
}
