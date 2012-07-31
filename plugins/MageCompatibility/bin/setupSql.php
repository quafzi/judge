<?php
$basedir = realpath(dirname(__FILE__) . '/../../../');
require_once $basedir . '/vendor/dg/dibi/dibi/dibi.php';
dibi::connect(array(
    'driver'   => 'sqlite3',
    'database' => $basedir . '/plugins/MageCompatibility/var/tags.sqlite'
));

dibi::query(
    'CREATE TABLE IF NOT EXISTS [classes] (
        [id] INTEGER NOT NULL PRIMARY KEY,
        [name] VARCHAR(100) NOT NULL)'
);

dibi::query(
    'CREATE TABLE IF NOT EXISTS [class_signature] (
        [class_id] INTEGER NOT NULL REFERENCES [classes](id),
        [signature_id] INTEGER NOT NULL REFERENCES [signature](id),
        PRIMARY KEY (class_id, signature_id))'
);

dibi::query(
    'CREATE TABLE IF NOT EXISTS [methods] (
        [id] INTEGER  NOT NULL PRIMARY KEY,
        [name] VARCHAR(100) NOT NULL,
        [class_id] INTEGER REFERENCES [classes](id))'
);

dibi::query(
    'CREATE TABLE IF NOT EXISTS [method_signature] (
        [method_id] INTEGER NOT NULL REFERENCES [methods](id),
        [signature_id] INTEGER NOT NULL REFERENCES [signature](id),
        [visibility] INTEGER NOT NULL REFERENCES [methods](id),
        [required_params_count] INTEGER,
        [optional_params_count] INTEGER,
        [params] TEXT,
        PRIMARY KEY (method_id, signature_id))'
);

dibi::query(
    'CREATE TABLE IF NOT EXISTS [constants] (
        [id] INTEGER  NOT NULL PRIMARY KEY,
        [name] VARCHAR(100) NOT NULL,
        [class_id] INTEGER REFERENCES [classes](id))'
);

dibi::query(
    'CREATE TABLE IF NOT EXISTS [constant_signature] (
        [constant_id] INTEGER NOT NULL REFERENCES [constants](id),
        [signature_id] INTEGER NOT NULL REFERENCES [signature](id),
        PRIMARY KEY (constant_id, signature_id))'
);

dibi::query(
    'CREATE TABLE IF NOT EXISTS [signature] (
        [id] INTEGER  NOT NULL PRIMARY KEY,
        [type] VARCHAR(1) NOT NULL,
        [definition] VARCHAR(100) NOT NULL)'
);

dibi::query(
    'CREATE TABLE IF NOT EXISTS [magento] (
        [id] INTEGER  NOT NULL PRIMARY KEY,
        [edition] VARCHAR(100) NOT NULL,
        [version] VARCHAR(100) NOT NULL)'
);

dibi::query(
    'CREATE TABLE IF NOT EXISTS [magento_signature] (
        [magento_id] INTEGER NOT NULL REFERENCES [magento](id),
        [signature_id] INTEGER NOT NULL REFERENCES [signature](id),
        PRIMARY KEY (magento_id, signature_id))'
);

