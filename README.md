                _ _
               | | |
      ___  ____| | |__   ___  ___
     / __|/ _  | |  _ \ / _ \/ _ \
     \__ \ (_| | | |_) |  __/  __/
     |___/\__  |_|____/ \___|\___|
             | |
             |_|

## About
sqlbee is a simple application that queries the ecobee API and extracts thermostat and runtime report data into a mySQL database. This is essentially the data from the System Monitor section of ecobee Home IQ.

If you like this project, check out my other ecobee-related project: [beestat.io](https://beestat.io). It takes all the data from sqlbee and turns it into powerful graphs.

_This project uses the MIT License, which means you can do whatever you want with the code and that I am not liable for anything. Have fun!_

### What does it do?
- Extracts current thermostat data like temperature, humidity, setpoints, etc (supports multiple thermostats)
- Extracts runtime report data using the ecobee runtimeReport API endpoint\

### What does it NOT do?
- Does **NOT** offer a means of reading the extracted data
- Does **NOT** analyze or display the data

## Requirements
- An ecobee thermostat
- An ecobee developer account (free)
- A server with PHP (with the cURL extension) and mySQL; any recent verson of both should work fine

## Getting Started
1. Clone this project to a folder on your server.
2. Create the sqlbee database and tables on your mySQL database by running the SQL in `sqlbee.sql`.
3. Copy or rename configuration.example.php to configuration.php.
4. Create an ecobee developer account (https://www.ecobee.com/developers/).
5. Create your own ecobee app (Developer > Create New) and get the API key and set that as the `$client_id` variable in `configuration.php`. Use the PIN Authorization method when creating the app.
6. Set the `$database_*` variables in configuration.php to match your mySQL connection properties.
7. Execute setup.php by running `php -f setup.php` and follow the instructions.
8. Set up a cron job to run `cron.php` at your desired interval. Example crontab entry: `* * * * * php -f /var/www/sqlbee/cron.php`.

## Notes
- After getting the project running, you might notice that roughly the past 15 minutes of rows in runtime_report have missing data. This is because the API reports these rows but the ecobee only transmits it's local data every 15 minutes.
- Storage space is fairly minimal. Syncing thermostat history uses about 8,500 rows / 1.5MB per month per thermostat. Syncing sensor history uses about 8,500 rows / 1.5MB per month per sensor (the thermostat counts as a sensor).
