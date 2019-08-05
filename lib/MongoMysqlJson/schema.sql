--
-- Collections
--
CREATE TABLE `collections/xxx` (
  `id`                INT(11)                                                      NOT NULL AUTO_INCREMENT,
  `document`          JSON                                                         NOT NULL,
  `_id_virtual`       VARCHAR(24) AS (`document` ->> '$._id')                      NOT NULL UNIQUE COMMENT 'Id',
  `_o_virtual`        INT(10)     AS (`document` ->> '$._o')                       NOT NULL        COMMENT 'Order',
  `_by_virtual`       VARCHAR(24) AS (`document` ->> '$._by')                      NOT NULL        COMMENT 'Created by',
  `_created_virtual`  TIMESTAMP   AS (FROM_UNIXTIME(`document` ->> '$._created'))  NOT NULL        COMMENT 'Created at',
  `_mby_virtual`      VARCHAR(24) AS (`document` ->> '$._virtual')                     NULL        COMMENT 'Modified by',
  `_modified_virtual` TIMESTAMP   AS (FROM_UNIXTIME(`document` ->> '$._modified'))     NULL        COMMENT 'Modified at',
  `_pid_virtual`      VARCHAR(24) AS (`document` ->> '$._pid')                         NULL        COMMENT 'Parent id',
  PRIMARY KEY (`id`),
  INDEX `_o_virtual_index` (`_o_virtual`)
);

--
-- Accounts
--
CREATE TABLE `cockpit/accounts` (
  `id`                INT(11)                                                      NOT NULL AUTO_INCREMENT,
  `document`          JSON                                                         NOT NULL,
  `_id_virtual`       VARCHAR(24) AS (`document` ->> '$._id')                      NOT NULL UNIQUE,
  `i18n_virtual`      VARCHAR(8)  AS (`document` ->> '$.i18n')                     NOT NULL UNIQUE COMMENT 'Language',
  `user_virtual`      VARCHAR     AS (`document` ->> '$.user')                     NOT NULL UNIQUE COMMENT 'Username',
  `email_virtual`     VARCHAR     AS (`document` ->> '$.email')                    NOT NULL UNIQUE COMMENT 'Email',
  `group_virtual`     VARCHAR     AS (`document` ->> '$.group')                    NOT NULL UNIQUE COMMENT 'Group name',
  `active_virtual`    INT(1)      AS (FROM_UNIXTIME(`document` ->> '$._active'))       NULL        COMMENT 'Is active',
  `password_virtual`  VARCHAR(80) AS (FROM_UNIXTIME(`document` ->> '$._password'))     NULL        COMMENT 'Password hash',
  `_created_virtual`  TIMESTAMP   AS (FROM_UNIXTIME(`document` ->> '$._created'))  NOT NULL        COMMENT 'Created at',
  `_modified_virtual` TIMESTAMP   AS (FROM_UNIXTIME(`document` ->> '$._modified'))     NULL        COMMENT 'Modified at',
  PRIMARY KEY (`id`),
  INDEX `credential` (`user_virtual`, `password_virtual`)
);

--
-- Assets
--
CREATE TABLE `cockpit/assets` (
  `id`       INT(11) NOT NULL AUTO_INCREMENT,
  `document` JSON    NOT NULL,
  PRIMARY KEY (`id`)
);

--
-- Assets_folders
--
CREATE TABLE `cockpit/assets_folders` (
  `id`       INT(11) NOT NULL AUTO_INCREMENT,
  `document` JSON    NOT NULL,
  PRIMARY KEY (`id`)
);

--
-- Options
--
CREATE TABLE `cockpit/options` (
  `id`       INT(11) NOT NULL AUTO_INCREMENT,
  `document` JSON    NOT NULL,
  PRIMARY KEY (`id`)
);

--
-- Revisions
-- 
CREATE TABLE `cockpit/revisions` (
  `id`               INT(11)                                                     NOT NULL AUTO_INCREMENT,
  `document`         JSON                                                        NOT NULL,
  `data_virtual`     JSON        AS (`document` ->> '$.data')                    NOT NULL COMMENT 'Data',
  `meta_virtual`     VARCHAR     AS (`document` ->> '$.meta')                    NOT NULL COMMENT 'Collection name',
  `_id_virtual`      VARCHAR(24) AS (`document` ->> '$._id')                     NOT NULL COMMENT 'Id',
  `_oid_virtual`     VARCHAR(24) AS (`document` ->> '$._oid')                    NOT NULL COMMENT 'Object Id',
  `_creator_virtual` VARCHAR(24) AS (`document` ->> '$._creator')                NOT NULL COMMENT 'Created by',
  `_created_virtual` TIMESTAMP   AS (FROM_UNIXTIME(`document` ->> '$._created')) NOT NULL COMMENT 'Created at',
  PRIMARY KEY (`id`),
  INDEX `_oid` (`_oid_virtual`)
);

--
-- Webhooks
--
CREATE TABLE `cockpit/webhooks` (
  `id`       INT(11) NOT NULL AUTO_INCREMENT,
  `document` JSON    NOT NULL,
  PRIMARY KEY (`id`)
);
