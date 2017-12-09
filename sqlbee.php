<?php

namespace sqlbee;
require_once 'configuration.php';

class sqlbee {

  private $mysqli;

  private $error;

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

    set_error_handler(array($this, 'error_handler'));
    set_exception_handler(array($this, 'exception_handler'));
    register_shutdown_function(array($this, 'shutdown_handler'));

    $this->mysqli->query('start transaction') or die($this->mysqli->error);

    // Everything in this script is done in UTC time.
    date_default_timezone_set('UTC');
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
    $full_endpoint = $endpoint;
    if($full_endpoint !== 'authorize' && $full_endpoint !== 'token') {
      $full_endpoint = '/1/' . $full_endpoint;

      // For non-authorization endpoints, add the access_token header.
      $query = 'select * from token order by token_id desc limit 1';
      $result = $this->mysqli->query($query) or die($this->mysqli->error);
      $token = $result->fetch_assoc();
      curl_setopt($curl_handle, CURLOPT_HTTPHEADER , array(
        'Authorization: Bearer ' . $token['access_token']
      ));
    }
    else {
      $full_endpoint = '/' . $full_endpoint;
    }
    $url = 'https://api.ecobee.com' . $full_endpoint;

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
          "' . $this->mysqli->real_escape_string($full_endpoint) . '",
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
      // Authentication token has expired. Refresh your tokens.
      if ($auto_refresh_token === true) {
        $this->refresh_token($token['refresh_token']);
        return $this->ecobee($method, $endpoint, $arguments, false);
      }
      else {
        throw new \Exception($response['status']['message']);
      }
    }
    else if(isset($response['status']) === true && $response['status']['code'] !== 0) {
      // Any other error
      throw new \Exception($response['status']['message']);
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
            'includePrivacy' => true,
            'includeAudio' => true,
            'includeSensors' => true

            /**
             * 'includeReminders' => true
             *
             * While documented, this is not available for general API use
             * unless you are a technician user.
             *
             * The reminders and the includeReminders flag are something extra
             * for ecobee Technicians. It allows them to set and receive
             * reminders with more detail than the usual alert reminder type.
             * These reminders are only available to Technician users, which
             * is why you aren't seeing any new information when you set that
             * flag to true. Thanks for pointing out the lack of documentation
             * regarding this. We'll get this updated as soon as possible.
             *
             *
             * https://getsatisfaction.com/api/topics/what-does-includereminders-do-when-calling-get-thermostat?rfm=1
             */

            /**
             * 'includeSecuritySettings' => true
             *
             * While documented, this is not made available for general API
             * use unless you are a utility. If you try to include this an
             * "Authentication failed" error will be returned.
             *
             * Special accounts such as Utilities are permitted an alternate
             * method of authorization using implicit authorization. This
             * method permits the Utility application to authorize against
             * their own specific account without the requirement of a PIN.
             * This method is limited to special contractual obligations and
             * is not available for 3rd party applications who are not
             * Utilities.
             *
             * https://www.ecobee.com/home/developer/api/documentation/v1/objects/SecuritySettings.shtml
             * https://www.ecobee.com/home/developer/api/documentation/v1/auth/auth-intro.shtml
             *
             */

          )
        ))
      )
    );

    // Update each thermostat with the actual and desired values.
    foreach($response['thermostatList'] as $thermostat) {
      $query = '
        select
          thermostat_id
        from
          thermostat
        where
          identifier = "' . $this->mysqli->real_escape_string($thermostat['identifier']) . '"
      ';
      $result = $this->mysqli->query($query) or die($this->mysqli->error);
      if($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        $thermostat_id = $row['thermostat_id'];
      }
      else {
        throw new \Exception('Invalid thermostat identifier');
      }

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
          json_privacy = "' . $this->mysqli->real_escape_string(json_encode($thermostat['privacy'])) . '",
          json_version = "' . $this->mysqli->real_escape_string(json_encode($thermostat['version'])) . '",
          json_remote_sensors = "' . $this->mysqli->real_escape_string(json_encode($thermostat['remoteSensors'])) . '",
          json_audio = "' . $this->mysqli->real_escape_string(json_encode($thermostat['audio'])) . '"
        where
          thermostat_id = ' . $thermostat_id . '
      ';
      $this->mysqli->query($query) or die($this->mysqli->error);

      // Mark all sensors as deleted
      $query = '
        update
          sensor
        set
          deleted = 1
        where
          thermostat_id = "' . $this->mysqli->real_escape_string($thermostat_id) . '"
      ';
      $this->mysqli->query($query) or die($this->mysqli->error);

      // Create/update sensors.
      foreach($thermostat['remoteSensors'] as $sensor) {
        // Check to see if this thermostat already exists.
        $query = '
          select
            *
          from
            sensor
          where
                thermostat_id = "' . $this->mysqli->real_escape_string($thermostat_id) . '"
            and identifier = "' . $this->mysqli->real_escape_string($sensor['id']) . '"
        ';
        $result = $this->mysqli->query($query) or die($this->mysqli->error);

        // If this sensor does not already exist, create it.
        if($result->num_rows === 0) {
          $query = '
            insert into sensor(
              thermostat_id,
              identifier,
              name,
              type,
              code,
              in_use,
              json_capability
            )
            values(
              "' . $this->mysqli->real_escape_string($thermostat_id) . '",
              "' . $this->mysqli->real_escape_string($sensor['id']) . '",
              "' . $this->mysqli->real_escape_string($sensor['name']) . '",
              "' . $this->mysqli->real_escape_string($sensor['type']) . '",
              ' . ((isset($sensor['code']) === true) ? ('"' . $this->mysqli->real_escape_string($sensor['code']) . '"') : ('null')) . ',
              "' . ($sensor['inUse'] === true ? '1' : '0') . '",
              "' . $this->mysqli->real_escape_string(json_encode($sensor['capability'])) . '"
            )';
          $result = $this->mysqli->query($query) or die($this->mysqli->error);
        }
        else {
          $row = $result->fetch_assoc();
          $query = '
            update sensor set
              thermostat_id = "' . $this->mysqli->real_escape_string($thermostat_id) . '",
              name = "' . $this->mysqli->real_escape_string($sensor['name']) . '",
              type = "' . $this->mysqli->real_escape_string($sensor['type']) . '",
              code = ' . ((isset($sensor['code']) === true) ? ('"' . $this->mysqli->real_escape_string($sensor['code']) . '"') : ('null')) . ',
              in_use = "' . ($sensor['inUse'] === true ? '1' : '0') . '",
              json_capability = "' . $this->mysqli->real_escape_string(json_encode($sensor['capability'])) . '",
              deleted = 0
            where
              sensor_id = ' . $row['sensor_id'] . '
          ';
          $result = $this->mysqli->query($query) or die($this->mysqli->error);
        }
      }
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
        runtime_report_thermostat
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
   * runtime_report_thermostat and runtime_report_sensor tables.
   *
   * @param int $thermostat_id
   */
  private function sync_runtime_report_($thermostat_id, $begin_gmt, $end_gmt) {
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
          'columns' => implode(',', array_keys($columns)),
          'includeSensors' => true
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

      // Date and time are first two columns of the returned data.
      list($date, $time) = array_splice($row, 0, 2);

      // Put thermostat_id and date onto the front of the array to be inserted.
      array_unshift(
        $row,
        $thermostat_id,
        date('Y-m-d H:i:s', strtotime($date . ' ' . $time))
      );

      $insert = '("' . implode('","', $row) . '")';
      $insert = str_replace('""', 'null', $insert);
      $inserts[] = $insert;
    }

    foreach(array_merge(array('thermostat_id' => 'thermostat_id', 'timestamp' => 'timestamp'), $columns) as $column) {
      $on_duplicate_keys[] = '`' . $column . '` = values(`' . $column . '`)';
    }

    $query = 'insert into runtime_report_thermostat(`' . implode('`,`', array_merge(array('thermostat_id', 'timestamp'), array_values($columns))) . '`) values' . implode(',', $inserts) . ' on duplicate key update ' . implode(',', $on_duplicate_keys);
    $this->mysqli->query($query) or die($this->mysqli->error);


    // Check timestamp columns on both tables...I think the default value and the on update value can be removed as we use ecobee values here

    /**
     * runtime_report_sensor
     */

    // Get a list of all sensors in the database for this thermostat.
    $query = 'select * from sensor where thermostat_id = ' . $thermostat_id;
    $result = $this->mysqli->query($query) or die($this->mysqli->error);

    $sensors = array();
    while($row = $result->fetch_assoc()) {
      $sensors[$row['identifier']] = $row;
    }

    // Create a sensor metric array keyed by the silly identifier.
    $sensor_metrics = array();
    foreach($response['sensorList'][0]['sensors'] as $sensor_metric) {
      $sensor_metrics[$sensor_metric['sensorId']] = $sensor_metric;

      $sensor_identifier = substr(
        $sensor_metric['sensorId'],
        0,
        strrpos($sensor_metric['sensorId'], ':')
      );

      $sensor_metrics[$sensor_metric['sensorId']]['sensor'] = $sensors[$sensor_identifier];
    }

    // Construct a more sensible data object that maps everything properly.
    $objects = array();
    foreach($response['sensorList'][0]['data'] as $i => $row) {
      $row = explode(',', $row);
      $row = array_map('trim', $row);
      $row = array_map(array($this->mysqli, 'real_escape_string'), $row);

      // Date and time are first two columns of the returned data.
      $date = $row[0];
      $time = $row[1];
      $timestamp = date('Y-m-d H:i:s', strtotime($date . ' ' . $time));

      for($j = 2; $j < count($row); $j++) {
        $column = $response['sensorList'][0]['columns'][$j];
        $sensor_metric = $sensor_metrics[$column];
        $sensor = $sensor_metric['sensor'];
        $sensor_id = $sensor['sensor_id'];
        $sensor_metric_type = $sensor_metric['sensorType'];

        // Need to generate a unique key per row per sensor as each row of
        // returned data represents data from multiple sensors. ಠ_ಠ
        $key = $i . '_' . $sensor_id;

        if(isset($objects[$key]) === false) {
          $objects[$key] = array(
            'thermostat_id' => $thermostat_id,
            'sensor_id' => $sensor_id,
            'timestamp' => $timestamp,
            'temperature' => null,
            'humidity' => null,
            'occupancy' => null
          );
        }
        if($row[$j] !== '' && $row[$j] !== 'null') {
          $objects[$key][$sensor_metric_type] = $row[$j];
        }
      }
    }

    // Get a nice integer-indexed array from the silly keyed array from earlier.
    $objects = array_values($objects);

    // And finally do the actual insert
    $inserts = array();
    $on_duplicate_keys = array();
    if(count($objects) > 0) {
      $columns = array_keys($objects[0]);

      foreach($objects as $object) {
        $insert = '("' . implode('","', array_values($object)) . '")';
        $insert = str_replace('""', 'null', $insert);
        $inserts[] = $insert;
      }

      foreach($columns as $column) {
        $on_duplicate_keys[] = '`' . $column . '` = values(`' . $column . '`)';
      }

      $query = '
        insert into
          runtime_report_sensor(`' . implode('`,`', $columns) . '`)
        values' . implode(',', $inserts) . '
        on duplicate key update ' . implode(',', $on_duplicate_keys);
      $this->mysqli->query($query) or die($this->mysqli->error);
    }
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

  /**
   * Catches any non-exception errors (like requiring a file that does not
   * exist) so the script can finish nicely instead of dying and rolling back
   * the databaes transaction.
   *
   * @param number $code
   * @param string $message
   * @param string $file
   * @param number $line
   */
  public function error_handler($code, $message, $file, $line) {
    $this->error = array(
      'code' => $code,
      'message' => $message,
      'file' => $file,
      'line' => $line,
      'backtrace' => debug_backtrace(false)
    );

    die(); // Do not continue execution; shutdown handler will now run.
  }

  /**
   * Catches uncaught exceptions so the script can finish nicely instead of
   * dying and rolling back the database transaction.
   *
   * @param Exceptoion $e
   */
  public function exception_handler($e) {
    $this->error = array(
      'code' => $e->getCode(),
      'message' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine(),
      'backtrace' => $e->getTrace()
    );

    die(); // Do not continue execution; shutdown handler will now run.
  }

  /**
   * Runs last, checks one final time for errors. If any errors or exceptions
   * happened, log them to the error_log table, echo a few last words, then
   * die nicely.
   */
  public function shutdown_handler() {
    try {
      // If I didn't catch an error/exception with my handlers, look here...this
      // will catch fatal errors that I can't.
      $error = error_get_last();
      if($error !== null) {
        $this->error = array(
          'code' => $error['type'],
          'message' => $error['message'],
          'file' => $error['file'],
          'line' => $error['line'],
          'backtrace' => debug_backtrace(false)
        );
      }

      if(isset($this->error) === true) {
        $query = '
          insert into error_log(
            `json_error`
          )
          values(
            "' . $this->mysqli->real_escape_string(json_encode($this->error)) . '"
          )
        ';
        $this->mysqli->query($query) or die($this->mysqli->error);
        $this->mysqli->query('commit') or die($this->mysqli->error);

        die('An error occured, check the error_log table for more details. Please report the issue at https://github.com/ziebelje/sqlbee/issues.' . PHP_EOL);
      }
      else {
        $this->mysqli->query('commit') or die($this->mysqli->error);
      }

    }
    catch(\Exception $e) {
      // If something breaks above, just spit out some stuff to be helpful. This
      // will only catch actual exceptions, not errors.
      var_dump($this->error);
      print_r(array(
        'code' => $e->getCode(),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'backtrace' => $e->getTrace()
      ));
      $this->mysqli->query('commit') or die($this->mysqli->error);
      die('An error occured that could not be logged. See above for more details. Please report the issue at https://github.com/ziebelje/sqlbee/issues.' . PHP_EOL);
    }
  }

}
