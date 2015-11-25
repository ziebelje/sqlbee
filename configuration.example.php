<?php

namespace sqlbee;

abstract class configuration {
  // Database configuration details. It is recommended to set up a database user
  // with only create, read, and update permissions for the sqlbee table.
  public static $database_username = 'sqlbee';
  public static $database_password = 'password';
  public static $database_host = 'localhost';
  public static $database_name = 'sqlbee';

  // The Client ID or API key from your custom ecobee app.
  public static $client_id = 'your_client_id';

  // Set this to 'smartRead' if you don't want this application to have the
  // ability to change your thermostat settings. Leave it as 'smartWrite' to
  // enable functions like setting temperatures, resuming schedules, etc.
  public static $scope = 'smartWrite';

  // Whether or not to log api calls into the api_log table. This is nice for
  // debugging or for learning about what API calls are being made, but isn't
  // otherwise very useful.
  public static $log_api_calls = false;

  // For any debug output.
  public static $debug = false;
}
