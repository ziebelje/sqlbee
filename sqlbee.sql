start transaction;

create database `sqlbee`;
use `sqlbee`;

create table `api_log` (
  `api_log_id` int(10) unsigned not null auto_increment,
  `method` enum('get','post') collate utf8_unicode_ci not null,
  `endpoint` varchar(255) collate utf8_unicode_ci not null,
  `json_arguments` text collate utf8_unicode_ci not null,
  `response` text collate utf8_unicode_ci not null,
  `timestamp` timestamp not null default current_timestamp,
  `deleted` tinyint(1) not null default '0',
  primary key (`api_log_id`)
) engine=innodb default charset=utf8 collate=utf8_unicode_ci;

create table `thermostat` (
  `thermostat_id` int(10) unsigned not null auto_increment,
  `identifier` varchar(255) collate utf8_unicode_ci not null,
  `name` varchar(255) collate utf8_unicode_ci not null,
  `connected` tinyint(1) not null,
  `thermostat_revision` varchar(255) collate utf8_unicode_ci not null,
  `alert_revision` varchar(255) collate utf8_unicode_ci not null,
  `runtime_revision` varchar(255) collate utf8_unicode_ci not null,
  `internal_revision` varchar(255) collate utf8_unicode_ci not null,
  `json_equipment_status` text collate utf8_unicode_ci,
  `actual_temperature` decimal(3,1) default null,
  `actual_humidity` int(10) unsigned default null,
  `desired_heat` decimal(3,1) default null,
  `desired_cool` decimal(3,1) default null,
  `desired_humidity` int(10) unsigned default null,
  `desired_dehumidity` int(10) unsigned default null,
  `desired_fan_mode` varchar(255) collate utf8_unicode_ci default null,
  `deleted` tinyint(1) not null default '0',
  primary key (`thermostat_id`),
  unique key `identifier` (`identifier`)
) engine=innodb default charset=utf8 collate=utf8_unicode_ci;

create table `token` (
  `token_id` int(10) unsigned not null auto_increment,
  `access_token` char(32) collate utf8_unicode_ci not null,
  `refresh_token` char(32) collate utf8_unicode_ci not null,
  `timestamp` timestamp not null default current_timestamp,
  `deleted` tinyint(4) not null default '0',
  primary key (`token_id`)
) engine=innodb default charset=utf8 collate=utf8_unicode_ci;

commit;
