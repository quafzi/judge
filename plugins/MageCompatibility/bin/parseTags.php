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
        $data = array(
            'name' => $tag,
            'path' => $path
        );
        $signatureData = array(
            'type'          => 'class',
            'definition'    => $tag
        );
        $classes = dibi::query(
            'SELECT *
             FROM [classes] c
             INNER JOIN [class_signature] cs ON c.id = cs.class_id
             INNER JOIN [signature] s ON s.id = cs.signature_id
             WHERE name = %s AND path = %s',
            $tag,
            $path
        )->fetchAll();
        if (0 == count($classes)) {
            dibi::query('INSERT INTO [classes] %v', $data);
            $class_id = dibi::getInsertId();
            dibi::query('INSERT INTO [signature] %v', $signatureData);
            $signature_id = dibi::getInsertId();
            dibi::query('INSERT INTO [class_signature] %v', array('class_id' => $class_id, 'signature_id' => $signature_id));
        } else {
        }
        /* @TODO: check/add signature */
    }


    protected function addMethod($tag, $path, $codeLine)
    {
        $methods = dibi::query(
                    'SELECT *
                    FROM [methods] m
                    INNER JOIN [classes] c
                    WHERE m.name = %s AND c.path = %s',
                    $tag, $path)
            ->fetch();
        if ($methods == false) {
            $class = dibi::query('SELECT id FROM [classes] c WHERE c.path = %s', $path)
                ->fetch();
            $methodData = array(
                'name'      => $tag,
                'class_id'  => $class['id']
            );
            dibi::query('INSERT INTO [methods] %v', $methodData);
        }
    }

}
