<?php
$basedir = realpath(dirname(__FILE__) . '/../../../');
require_once $basedir . '/vendor/dg/dibi/dibi/dibi.php';
dibi::connect(array(
    'driver'   => 'sqlite3',
    'database' => $basedir . '/plugins/MageCompatibility/var/tags.sqlite'
));

if (count($argv) < 2) {
    die('Please submit tag file path to the tag file.' . PHP_EOL);
}
$tagFileName = $argv[1];
if (false == file_exists($tagFileName)) {
    die('Please submit the correct(!) tag file path to the tag file.' . PHP_EOL);
}

$parser = new TagParser($tagFileName);
$parser->run();

class TagParser
{
    protected $tagFileName;

    protected $knownClasses=array();

    public function __construct($tagFileName) {
        $this->tagFileName = $tagFileName;
    }

    public function run()
    {
        $tagFile = fopen($this->tagFileName, 'r');
        $tagFileLineNumber = 0;
        while ($line = fgets($tagFile)) {
            ++$tagFileLineNumber;
            if ('!_T' == substr($line, 0,3)) {
                // skip comment lines
                continue;
            }
            list($tag, $path, $codeLine, $type, $sourceLineNumber) = explode("\t", $line);
            switch ($type) {
                case 'c': 
                    $this->addClass($tag, $path, $codeLine);
                    break;
                case 'f':
                    $this->addMethod($tag, $path, $codeLine);
            }
        }
    }

    protected function addClass($tag, $path, $codeLine)
    {
        $data = array('name' => $tag);
        $classes = dibi::query('SELECT * FROM [classes] WHERE name = %s', $tag)->fetchAll();
        if (0 == count($classes)) {
            dibi::query('INSERT INTO [classes] %v', $data);
        } else {
            die(var_dump(__FILE__ . ' on line ' . __LINE__ . ':', $classes));
        }
        /* @TODO: check/add signature */
    }
}
