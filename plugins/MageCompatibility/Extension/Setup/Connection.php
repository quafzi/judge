<?php
namespace MageCompatibility\Extension\Setup;

use MageCompatibility\Extension\Setup;

class Connection extends Setup
{
    public function __construct()
    {
    }

    public function addColumn($table, $field, $options) {
        $this->addField($table, $field);
    }

    public function getChanges()
    {
        return $this->changes;
    }

    public function __call($method, $args)
    {
        return $this;
    }
}
