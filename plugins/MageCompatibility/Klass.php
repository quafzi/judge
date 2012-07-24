<?php
namespace MageCompatibility;

class Klass
{
    protected $name;

    /**
     * create Klass with given name
     * 
     * @param string $name 
     * @return Klass
     */
    public function __construct($name)
    {
        $this->setName($name);
    }

    /**
     * set class name
     * 
     * @param string $name 
     * @return Klass
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    public function getName()
    {
        return $this->name;
    }
}

