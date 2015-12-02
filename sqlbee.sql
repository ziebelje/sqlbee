start transaction;

create database `sqlbee`;
use `sqlbee`;

create table `sqlbee_api_log` (
  `sqlbee_api_log_id` int(10) unsigned not null auto_increment,
  `method` enum('get','post') collate utf8_unicode_ci not null,
  `endpoint` varchar(255) collate utf8_unicode_ci not null,
  `json_arguments` text collate utf8_unicode_ci not null,
  `response` text collate utf8_unicode_ci not null,
  `timestamp` timestamp not null default current_timestamp,
  `deleted` tinyint(1) not null default '0',
  primary key (`sqlbee_api_log_id`)
) engine=innodb default charset=utf8 collate=utf8_unicode_ci;

create table `sqlbee_thermostat` (
  `sqlbee_thermostat_id` int(10) unsigned not null auto_increment,
  `identifier` varchar(255) collate utf8_unicode_ci not null,
  `name` varchar(255) collate utf8_unicode_ci not null,
  `connected` tinyint(1) not null,
  `thermostat_revision` varchar(255) collate utf8_unicode_ci not null,
  `alert_revision` varchar(255) collate utf8_unicode_ci not null,
  `runtime_revision` varchar(255) collate utf8_unicode_ci not null,
  `internal_revision` varchar(255) collate utf8_unicode_ci not null,
  `json_runtime` text collate utf8_unicode_ci,
  `json_extended_runtime` text collate utf8_unicode_ci,
  `json_electricity` text collate utf8_unicode_ci,
  `json_settings` text collate utf8_unicode_ci,
  `json_location` text collate utf8_unicode_ci,
  `json_program` text collate utf8_unicode_ci,
  `json_events` text collate utf8_unicode_ci,
  `json_device` text collate utf8_unicode_ci,
  `json_technician` text collate utf8_unicode_ci,
  `json_utility` text collate utf8_unicode_ci,
  `json_management` text collate utf8_unicode_ci,
  `json_alerts` text collate utf8_unicode_ci,
  `json_weather` text collate utf8_unicode_ci,
  `json_house_details` text collate utf8_unicode_ci,
  `json_oem_cfg` text collate utf8_unicode_ci,
  `json_equipment_status` text collate utf8_unicode_ci,
  `json_notification_settings` text collate utf8_unicode_ci,
  `json_version` text collate utf8_unicode_ci,
  `json_remote_sensors` text collate utf8_unicode_ci,
  `deleted` tinyint(1) not null default '0',
  primary key (`sqlbee_thermostat_id`),
  unique key `identifier` (`identifier`)
) engine=innodb default charset=utf8 collate=utf8_unicode_ci;

create table `sqlbee_runtime_report` (
  `sqlbee_runtime_report_id` int(10) unsigned not null auto_increment,
  `sqlbee_thermostat_id` int(10) unsigned not null,
  `timestamp` timestamp not null default current_timestamp on update current_timestamp,
  `auxiliary_heat_1` int(10) unsigned default null,
  `auxiliary_heat_2` int(10) unsigned default null,
  `auxiliary_heat_3` int(10) unsigned default null,
  `compressor_cool_1` int(10) unsigned default null,
  `compressor_cool_2` int(10) unsigned default null,
  `compressor_heat_1` int(10) unsigned default null,
  `compressor_heat_2` int(10) unsigned default null,
  `dehumidifier` int(10) unsigned default null,
  `demand_management_offset` decimal(3,1) unsigned default null,
  `economizer` int(10) unsigned default null,
  `fan` int(10) unsigned default null,
  `humidifier` int(10) unsigned default null,
  `outdoor_humidity` int(10) unsigned default null,
  `outdoor_temperature` decimal(3,1) unsigned default null,
  `sky` int(10) unsigned default null,
  `ventilator` int(10) unsigned default null,
  `wind` int(10) unsigned default null,
  `zone_average_temperature` decimal(3,1) unsigned default null,
  `zone_calendar_event` varchar(255) collate utf8_unicode_ci default null,
  `zone_cool_temperature` decimal(3,1) unsigned default null,
  `zone_heat_temperature` decimal(3,1) unsigned default null,
  `zone_humidity` int(10) unsigned default null,
  `zone_humidity_high` int(10) unsigned default null,
  `zone_humidity_low` int(10) unsigned default null,
  `zone_hvac_mode` varchar(255) collate utf8_unicode_ci default null,
  `zone_occupancy` int(10) unsigned default null,
  `deleted` tinyint(1) not null default '0',
  primary key (`sqlbee_runtime_report_id`),
  unique key `sqlbee_thermostat_id_timestamp` (`sqlbee_thermostat_id`,`timestamp`),
  constraint `sqlbee_runtime_report_ibfk_1` foreign key (`sqlbee_thermostat_id`) references `sqlbee_thermostat` (`sqlbee_thermostat_id`)
) engine=innodb default charset=utf8 collate=utf8_unicode_ci;

create table `sqlbee_token` (
  `sqlbee_token_id` int(10) unsigned not null auto_increment,
  `access_token` char(32) collate utf8_unicode_ci not null,
  `refresh_token` char(32) collate utf8_unicode_ci not null,
  `timestamp` timestamp not null default current_timestamp,
  `deleted` tinyint(4) not null default '0',
  primary key (`sqlbee_token_id`)
) engine=innodb default charset=utf8 collate=utf8_unicode_ci;

commit;
