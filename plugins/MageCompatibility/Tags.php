<?php
namespace MageCompatibility;

class Tags implements \Iterator
{
    protected $position = 0;
    protected $data = array();

    public function count()
    {
        return count($this->data);
    }

    public function current()
    {
        return $this->data[$this->position];
    }

    public function rewind()
    {
        $this->position = 0;
    }

    public function key()
    {
        return $this->position;
    }
        
    function next()
    {
        ++$this->position;
    }

    function valid()
    {
        return isset($this->data[$this->position]);
    }
}
