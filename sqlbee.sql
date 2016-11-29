start transaction;

create database `sqlbee`;
use `sqlbee`;

create table `sqlbee_api_log` (
  `sqlbee_api_log_id` int(10) unsigned not null auto_increment,
  `method` enum('get','post') not null,
  `endpoint` varchar(255) not null,
  `json_arguments` text not null,
  `response` text not null,
  `timestamp` timestamp not null default current_timestamp,
  `deleted` tinyint(1) not null default '0',
  primary key (`sqlbee_api_log_id`)
) engine=innodb default charset=utf8 collate=utf8_unicode_ci;

create table `sqlbee_thermostat` (
  `sqlbee_thermostat_id` int(10) unsigned not null auto_increment,
  `identifier` varchar(255) not null,
  `name` varchar(255) not null,
  `connected` tinyint(1) not null,
  `thermostat_revision` varchar(255) not null,
  `alert_revision` varchar(255) not null,
  `runtime_revision` varchar(255) not null,
  `internal_revision` varchar(255) not null,
  `json_runtime` text,
  `json_extended_runtime` text,
  `json_electricity` text,
  `json_settings` text,
  `json_location` text,
  `json_program` text,
  `json_events` text,
  `json_device` text,
  `json_technician` text,
  `json_utility` text,
  `json_management` text,
  `json_alerts` text,
  `json_weather` text,
  `json_house_details` text,
  `json_oem_cfg` text,
  `json_equipment_status` text,
  `json_notification_settings` text,
  `json_version` text,
  `json_remote_sensors` text,
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
  `zone_calendar_event` varchar(255) default null,
  `zone_cool_temperature` decimal(3,1) unsigned default null,
  `zone_heat_temperature` decimal(3,1) unsigned default null,
  `zone_humidity` int(10) unsigned default null,
  `zone_humidity_high` int(10) unsigned default null,
  `zone_humidity_low` int(10) unsigned default null,
  `zone_hvac_mode` varchar(255) default null,
  `zone_occupancy` int(10) unsigned default null,
  `deleted` tinyint(1) not null default '0',
  primary key (`sqlbee_runtime_report_id`),
  unique key `sqlbee_thermostat_id_timestamp` (`sqlbee_thermostat_id`,`timestamp`),
  constraint `sqlbee_runtime_report_ibfk_1` foreign key (`sqlbee_thermostat_id`) references `sqlbee_thermostat` (`sqlbee_thermostat_id`)
) engine=innodb default charset=utf8 collate=utf8_unicode_ci;

create table `sqlbee_token` (
  `sqlbee_token_id` int(10) unsigned not null auto_increment,
  `access_token` char(32) not null,
  `refresh_token` char(32) not null,
  `timestamp` timestamp not null default current_timestamp,
  `deleted` tinyint(4) not null default '0',
  primary key (`sqlbee_token_id`)
) engine=innodb default charset=utf8 collate=utf8_unicode_ci;

commit;
