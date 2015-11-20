# sqlbee

## About
sqlbee is an incredibly simple application that queries the ecobee API and extracts thermostat and runtime report data into a mySQL database. This is essentially the data from the System Monitor section of ecobee Home IQ. If you want your ecobee data available to you for querying or display but don't want to mess with their API, then this is for you. It was written for personal use but I'll be happy to answer questions or add support for other things if I have time.

_This project uses the MIT License, which means you can do whatever you want with the code and that I am not liable for anything. Have fun!_

### What does it do?
- Extracts current thermostat data like temperature, humidity, setpoints, etc (supports multiple thermostats)
- [TODO] Extracts runtime report data using the ecobee runtimeReport API endpoint
- [TODO] Provides a basic means of connecting up custom endpoints to meaningful events like temperature or setpoint changes

### What does it NOT do?
- Does **NOT** change thermostat temperatures or settings
- Does **NOT** offer a means of reading the extracted data
- Does **NOT** analyze or display the data
- Does **NOT** make any changes to your ecobee; sqlbee is read only

## Requirements
- An ecobee thermostat
- An ecobee developer account
- A server with PHP and mySQL (any recent versions should probably work)

## Getting Started
1. Create the sqlbee database and tables on your mySQL database by running `sqlbee.sql`
2. Rename configuration.example.php to configuration.php
3. Set the `$database_*` variables in configuration.php to match your mySQL connection properties
4. Create an ecobee developer account (https://www.ecobee.com/developers/)
5. Create your own ecobee app and get the API key and set that as the `$client_id` variable in `configuration.php`
6. Execute setup.php by running `php -f setup.php` and follow the instructions
7. Set up a cron job to run `cron.php` at your desired interval
