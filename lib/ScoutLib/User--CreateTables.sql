
#
#   Axis--User.php
#   SQL Table Creation Code
#
#   Copyright 1999-2022 Axis Data
#   This code is free software that can be used or redistributed under the
#   terms of Version 2 of the GNU General Public License, as published by the
#   Free Software Foundation (http://www.fsf.org).
#
#   Author:  Edward Almasy (almasy@axisdata.com)
#
#   Part of the AxisPHP library v1.2.4
#   For more information see http://www.axisdata.com/AxisPHP/
#


CREATE TABLE IF NOT EXISTS APUsers (
  UserId                INT NOT NULL AUTO_INCREMENT,
  UserName              TEXT NOT NULL,
  UserPassword          TEXT DEFAULT NULL,
  CreationDate          DATETIME DEFAULT NULL,
  LastLoginDate         DATETIME DEFAULT NULL,
  LoggedIn              INT DEFAULT 0,
  RegistrationConfirmed INT DEFAULT 0,
  EMail                 TEXT DEFAULT NULL,
  EMailNew              TEXT DEFAULT NULL,
  WebSite               TEXT DEFAULT NULL,
  RealName              TEXT DEFAULT NULL,
  AddressLineOne        TEXT DEFAULT NULL,
  AddressLineTwo        TEXT DEFAULT NULL,
  City                  TEXT DEFAULT NULL,
  State                 TEXT DEFAULT NULL,
  Country               TEXT DEFAULT NULL,
  ZipCode               TEXT DEFAULT NULL,
  LastLocation          TEXT DEFAULT NULL,
  LastActiveDate        DATETIME DEFAULT NULL,
  LastIPAddress         TEXT DEFAULT NULL,
  INDEX                 Index_U (UserId),
  INDEX                 Index_Un (UserName(12))
);

CREATE TABLE IF NOT EXISTS APUserPrivileges (
  UserId            INT NOT NULL,
  Privilege         INT NOT NULL,
  INDEX             Index_U (UserId),
  INDEX             Index_P (Privilege)
);
