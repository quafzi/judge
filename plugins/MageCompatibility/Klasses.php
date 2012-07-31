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
    public function add(Klass $class)
    {
        $this->classes[$class->getName()] = $class;
        return $this;
    }

    public function compareToMagentoTags($tagFileName)
    {
        ksort($this->classes);
        foreach ($this->classes as $class) {
            die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $class));
        }
    }

    public function count()
    {
        return count($this->classes);
    }
}
