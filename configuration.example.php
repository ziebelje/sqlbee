<?php

namespace sqlbee;

/**
 * All of the configuration settings. Updating the $database_* and $client_id
 * values is all that is required. Other settings can be changed to do other
 * advanced things.
 */
abstract class configuration {
  /**
   * The Client ID or API key from your custom ecobee app.
   */
  public static $client_id = 'your_client_id';

  /**
   * Database configuration details. It is recommended to set up a database
   * user with only create, read, and update permissions for the sqlbee table.
   *
   * Recommend creating a sqlbee user by doing something like the following:
   * create user 'sqlbee'@'localhost' identified by 'password';
   * grant insert, select, update on sqlbee.* to 'sqlbee'@'localhost';
   */
  public static $database_username = 'sqlbee';
  public static $database_password = 'password';
  public static $database_host     = 'localhost';
  public static $database_name     = 'sqlbee';

  /**
   * Set this to 'smartRead' if you don't want this application to have the
   * ability to change your thermostat settings. Set this to 'smartWrite' to
   * enable functions like setting temperatures, resuming schedules, etc.
   */
  public static $scope = 'smartRead';

  /**
   * Whether or not to log api calls into the api_log table. This is nice for
   * debugging or for learning about what API calls are being made, but isn't
   * otherwise very useful.
   */
  public static $log_api_calls = false;

  /**
   * This gets set to true during setup to help with some output.
   */
  public static $setup = false;
}
