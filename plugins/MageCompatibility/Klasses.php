<?php
namespace MageCompatibility;

class Klasses
{
    protected $classes = array();

    /**
     * add a class name
     * 
     * @param string $name Name of the class
     * @return Klasses
     */
    public function add($name)
    {
        $this->classes[$name] = new Klass($name);
        return $this;
    }

    public function compareToMagentoTags($tagFileName)
    {
        ksort($this->classes);
        foreach ($this->classes as $class) {
            die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $class));
        }
    }
}
