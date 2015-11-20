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
      throw new \Exception($mysqli->connect_error . '(' . $mysqli->connect_errno . ')');
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
        'Content-type: application/json',
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
        'scope' => 'smartRead'
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
   * Given a list of thermostats, get and update the runtime data in the
   * thermostat table.
   *
   * @param array $thermostat_identifiers
   *
   * @see https://www.ecobee.com/home/developer/api/documentation/v1/operations/get-thermostats.shtml
   * @see https://www.ecobee.com/home/developer/api/documentation/v1/objects/Selection.shtml
   */
  public function get_thermostats($thermostat_identifiers) {
    if(count($thermostat_identifiers) > 25) {
      throw new \Exception('No more than 25 identifiers allowed. See https://www.ecobee.com/home/developer/api/documentation/v1/objects/Selection.shtml');
    }
    else {
      $response = $this->ecobee(
        'GET',
        'thermostat',
        array(
          'body' => json_encode(array(
            'selection' => array(
              'selectionType' => 'thermostats',
              'selectionMatch' => implode(',', $thermostat_identifiers),
              'includeRuntime' => true
            )
          ))
        )
      );

      // Update each thermostat with the actual and desired values.
      foreach($response['thermostatList'] as $thermostat) {
        $query = '
          update
            thermostat
          set
            actual_temperature = "' . $this->mysqli->real_escape_string($thermostat['runtime']['actualTemperature'] / 10) . '",
            actual_humidity = "' . $this->mysqli->real_escape_string($thermostat['runtime']['actualHumidity']) . '",
            desired_heat = "' . $this->mysqli->real_escape_string($thermostat['runtime']['desiredHeat'] / 10) . '",
            desired_cool = "' . $this->mysqli->real_escape_string($thermostat['runtime']['desiredCool'] / 10) . '",
            desired_humidity = "' . $this->mysqli->real_escape_string($thermostat['runtime']['desiredHumidity']) . '",
            desired_dehumidity = "' . $this->mysqli->real_escape_string($thermostat['runtime']['desiredDehumidity']) . '",
            desired_fan_mode = "' . $this->mysqli->real_escape_string($thermostat['runtime']['desiredFanMode']) . '"
          where
            identifier = "' . $thermostat['identifier'] . '"
        ';
        $this->mysqli->query($query) or die($this->mysqli->error);
      }
    }
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
            'selectionMatch' => '',
            'includeEquipmentStatus' => true
          )
        ))
      )
    );

    // Mark all thermostats as deleted
    $query = 'update thermostat set deleted = 1';
    $this->mysqli->query($query) or die($this->mysqli->error);

    $return = array();

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
      }

      $diff = array_diff($thermostat, $original_thermostat);
      $return[$original_thermostat['identifier']] = array_intersect_key($diff, array_flip(array('thermostat_revision', 'alert_revision', 'runtime_revision', 'internal_revision')));
    }

    // Add in the status data.
    foreach($response['statusList'] as $equipment_status) {
      // Mutate the return data a bit.
      $equipment_status = explode(':', $equipment_status);
      $identifier = array_shift($equipment_status);

      if($equipment_status[0] === '') {
        $equipment_status = array();
      }
      else {
        $equipment_status = explode(',', $equipment_status[0]);
      }

      $query = '
        update
          thermostat
        set
          json_equipment_status = "' . $this->mysqli->real_escape_string(json_encode($equipment_status)) . '"
        where
          identifier = "' . $this->mysqli->real_escape_string($identifier) . '"';
      $this->mysqli->query($query) or die($this->mysqli->error);
    }

    // Return the most recent values for any revision columns that have changed.
    // If it's a new thermostat, all of them will be returned. Keyed by thermostat_id.
    return $return;
  }

}
