<?php
$basedir = realpath(dirname(__FILE__) . '/../../../');
require_once $basedir . '/vendor/dg/dibi/dibi/dibi.php';
dibi::connect(array(
    //'driver'   => 'sqlite3',
    //'database' => $basedir . '/plugins/MageCompatibility/var/tags.sqlite'
    'driver'   => 'mysql',
	'username' => 'root',
	'database' => 'judge'
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

    protected $version;
    protected $edition;
    protected $magentoId;

    protected $knownClasses=array();

    public function __construct($tagFileName) {
        $this->tagFileName = $tagFileName;
    }

    public function run()
    {
        $startedAt = time();
        $tagFile = fopen($this->tagFileName, 'r');
        $tagFileNameWithoutExt = str_replace('.tags', '', basename($this->tagFileName));
        list($edition, $this->version) = explode('-', $tagFileNameWithoutExt);
        $this->edition = ucfirst(substr($edition, 0, 1)) . 'E';
        $types = array(
            'c' => 'addClass',     // classes
            'i' => 'addInterface', // interfaces
            'd' => 'addConstant',  // constant definitions
            'f' => 'addMethod',    // functions
            'm' => 'addMethod',    // functions
            /* tag types to be ignored
            'v' => 'addVariable',  // variables
            'j' => 'addJavascript' // javascript functions
             */
        );

        $done = 0;
        exec('wc -l ' . $this->tagFileName, $wcOut);
        $lines = (int) current($wcOut);
        $ignore = 0;
        foreach ($types as $currentType=>$call) {
            rewind($tagFile);
            $tagFileLineNumber = 0;
            $ignore = 0;
            while ($line = fgets($tagFile)) {
                ++$tagFileLineNumber;
                if ('!_T' == substr($line, 0,3)) {
                    // skip comment lines
                    ++$ignore;
                    continue;
                }
                list($tag, $path, $codeLine, $type, $sourceLineNumber) = explode("\t", $line);
                $codeLine = str_replace('/^', '', $codeLine);
                $codeLine = str_replace('$/;"', '', $codeLine);
                if (0 === strpos('downloader/', $path)) {
                    // skip downloader
                    ++$ignore;
                    continue;
                }

                if (1 != strlen($type)) {
                    echo "found invalid type \"$type\" on line $tagFileLineNumber";
                    exit(1);
                }

                if ($currentType == $type) {
                    dibi::begin();
                    $this->$call($tag, $path, trim($codeLine));
                    dibi::commit();
                    ++$done;
                    $called = $call;
                } else {
                    $called = "(skip $type)";
                }
                $timeLeft = '';
                if (20 < $done) {
                    $timeSpent = time() - $startedAt;
                    $secondsLeft = round($lines * $timeSpent / $done) - $timeSpent;
                    $estimatedEnd = date('H:i', time() + $secondsLeft);
                    $timeLeft = ", approx. {$secondsLeft}s left";
                    if (90 < $secondsLeft) {
                        $minutesLeft = round($secondsLeft / 60);
                        $timeLeft = ", approx. {$minutesLeft}min left";
                        if (90 < $minutesLeft) {
                            $hoursLeft = round($minutesLeft / 60);
                            $timeLeft = ", approx. {$hoursLeft}h left";
                        }
                    }
                    $timeLeft .= ' - should finish at ' . $estimatedEnd . '';
                }
                $memusage = ', ' . round(memory_get_usage()/1000)/1000 . 'MB';
                $percent = number_format(100 * $done / ($lines - $ignore), 3);
                echo "\r  ➜ $done/" . ($lines-$ignore) . " done ($percent%$timeLeft$memusage, tag line $tagFileLineNumber): $called      ";

            }
            echo "\r  ➜ $done/" . ($lines-$ignore) . " done ($percent%$timeLeft$memusage, tag line $tagFileLineNumber): finished $call        \n";
        }
    }

    /**
     * add class to database
     *
     * @param mixed $name
     * @param mixed $path
     * @param mixed $codeLine
     * @return void
     */
    protected function addClass($name, $path, $codeLine)
    {
        $data = array('name' => $name, 'path' => $path);
        $classData = dibi::fetch('
            SELECT t.id as classId, COALESCE(s.id,0) as signatureId
            FROM [classes] t
                LEFT JOIN [class_signature] ts ON ( t.id = ts.class_id )
                LEFT JOIN [signatures] s ON ( ts.signature_id = s.id AND s.definition = %s)
            WHERE name = %s AND t.path = %s ORDER BY signatureId DESC',
            $codeLine,
            $name,
            $path
        );
        if (false === $classData || 0 == $classData->signatureId) {
            $signatureId = $this->createSignature('c', $codeLine, $path);
            if (false === $classData) {
                dibi::query('INSERT INTO [classes] %v', $data);
                $classId = dibi::getInsertId();
            } else {
                $classId = $classData->classId;
            }
            dibi::query(
                'INSERT INTO [class_signature] %v',
                array('class_id' => $classId, 'signature_id' => $signatureId)
            );
        } else {
            $signatureId = $classData->signatureId;
        }
        $this->assignSignatureToMagento($signatureId);
    }

    protected function addInterface($name, $path, $codeLine)
    {
        $this->addClass($name, $path, $codeLine);
    }

    /**
     * addConstant
     *
     * @param mixed $name
     * @param mixed $path
     * @param mixed $codeLine
     * @return void
     */
    protected function addConstant($name, $path, $codeLine)
    {
        $data = array('name' => $name);
        $className = $this->getClassNameForPath($path);
        $classId   = $this->fetchClassId($className);
        $constants = dibi::query('SELECT * FROM [constants] c WHERE name = %s', $name)->fetchAll();
        $signatureId = $this->fetchSignatureId('d', trim($codeLine), $path);
        if (0 == count($constants)) {
            dibi::query('INSERT INTO [constants] %v', $data);
            $constantId = dibi::getInsertId();
            dibi::query('INSERT INTO [constant_signature] %v', array('constant_id' => $constantId, 'signature_id' => $signatureId));
        }
    }

    /**
     * add method
     *
     * @param mixed $tag
     * @param mixed $path
     * @param mixed $codeLine
     * @return void
     */
    protected function addMethod($name, $path, $codeLine)
    {
        $className   = $this->getClassNameForPath($path);
        $classId     = $this->fetchClassId($className);
        $methodId    = $this->fetchMethodId($name, $classId);
        $signatureId = $this->fetchSignatureId('m', trim($codeLine), $path);
        $relation = dibi::query(
            'SELECT * FROM [method_signature] WHERE method_id = %s and signature_id = %s',
            $methodId,
            $signatureId
        )->fetch();
        if (false == $relation) {
            $countOfParams = $this->getCountOfParams($codeLine);
            $data = array(
                'method_id'             => $methodId,
                'signature_id'          => $signatureId,
                'required_params_count' => $countOfParams['required'],
                'optional_params_count' => $countOfParams['optional'],
                'visibility'            => $this->getVisibility($codeLine)
            );
            dibi::query('INSERT INTO [method_signature] %v', $data);
        }
    }

    protected function getCountOfParams($call)
    {
        $count = array(
            'required' => 0,
            'optional' => 0
        );
        preg_match('/([^\(]*)\((.*)\)/', $call, $matches);
        $params = '';
        if (0 < count($matches)) {
            list ($call, $method, $params) = $matches;
        }

        if (strlen($params)) {
            $params = explode(',', $params);
            $countOfRequiredParams = 0;
            $countOfOptionalParams = 0;
            foreach ($params as $param) {
                if (false === strpos($param, '=')) {
                    $count['required']++;
                } else {
                    $count['optional']++;
                }
            }
        }
        return $count;
    }

    protected function getVisibility($definition)
    {
        if (preg_match('/.*(public|protected|private).*function\ +(\w+)\W*\(/', $definition, $matches)) {
            return $matches[1];
        }
    }

    protected function assignSignatureToMagento($signatureId)
    {
        $magentoId = $this->fetchMagentoId();
        $relation = dibi::query(
            'SELECT * FROM [magento_signature] WHERE signature_id = %s AND magento_id = %s',
            $signatureId,
            $magentoId
        )->fetch();
        if (false == $relation) {
            $data = array(
                'signature_id' => $signatureId,
                'magento_id'   => $magentoId
            );
            dibi::query('INSERT INTO [magento_signature] %v', $data);
        }
    }

    protected function fetchMagentoId()
    {
        if (is_null($this->magentoId)) {
            $this->magentoId = dibi::query(
                'SELECT * FROM [magento] WHERE edition = %s AND version = %s',
                $this->edition,
                $this->version
            )->fetchSingle();
            if (false == $this->magentoId) {
                $magento = array(
                    'edition' => $this->edition,
                    'version' => $this->version
                );
                dibi::query('INSERT INTO [magento] %v', $magento);
                $this->magentoId = dibi::getInsertId();
            }
        }
        return $this->magentoId;
    }

    /**
     * create class if needed, return its id
     *
     * @param string $name
     * @return int
     */
    protected function fetchClassId($name)
    {
        if (0 == strlen ($name)) {
            return null;
        }
        $data = array('name' => $name);
        $class = dibi::query('SELECT * FROM [classes] WHERE name = %s', $name)->fetch();
        if (false == $class) {
            dibi::query('INSERT INTO [classes] %v', $data);
            return dibi::getInsertId();
        }
        return $class->id;
    }

    /**
     * create method if needed, return its id
     *
     * @param string $name
     * @return int
     */
    protected function fetchMethodId($name, $classId)
    {
        $data = array(
            'name'     => $name,
            'class_id' => $classId,
        );
        $method = dibi::query('SELECT * FROM [methods] WHERE name = %s AND class_id = %s', $name, $classId)->fetch();
        if (false == $method) {
            dibi::query('INSERT INTO [methods] %v', $data);
            return dibi::getInsertId();
        }
        return $method->id;
    }

    /**
     * create signature if needed, return its id
     *
     * @param char   $type
     * @param string $definition
     * @param string $path
     * @return int
     */
    protected function fetchSignatureId($type, $definition, $path)
    {
        $signature = dibi::query(
            'SELECT * FROM [signatures] WHERE type = %s AND definition = %s AND path = %s',
            $type,
            $definition,
            $path
        )->fetch();
        if (false == $signature) {
            $signatureId = $this->createSignature($type, $definition, $path);
        } else {
            $signatureId = $signature->id;
            $this->assignSignatureToMagento($signatureId);
        }
        return $signatureId;
    }

    protected function createSignature($type, $definition, $path)
    {
        $data = array(
            'type'       => $type,
            'definition' => $definition,
            'path'       => $path
        );
        dibi::query('INSERT INTO [signatures] %v', $data);
        $signatureId = dibi::getInsertId();
        $this->assignSignatureToMagento($signatureId);
        return $signatureId;
    }

    public function getClassNameForPath($path)
    {
        if (0 === strpos($path, 'database[')) {
            /* if that is a database accessor we already have the class name in the path */
            if (preg_match('/^database\[([^\/]+)/', $path, $matches)) {
                return $matches[1];
            }
            return '';
        }
        $fileExtensions = explode('.', $path);

        if (!in_array(end($fileExtensions), array('php'))) {
            return '';
        }
        /**
         * since Mage.php is a special case we handle it separately
         */
        if ('app/Mage.php' == $path) {
            return 'Mage';
        }
        /**
         * lib/Zend/Foo/Bar.php
         * app/code/core/Foo/Bar/Some/Path.php
         */
        $irrelevantPathParts = array(
            'lib/3Dsecure',
            'lib/googlecheckout',
            'lib/PEAR',
            'lib/phpseclib',
            'lib/',
            'app/code/core/',
            'app/code/community/',
            'app/code/local/',
            '.php',
        );
        foreach ($irrelevantPathParts as $part)
        {
            $path = str_replace($part, '', $path);
        }
        $relevantParts = explode('/', $path);
        foreach ($relevantParts as $key=>$part) {
            if (0 == strlen($part)) {
                unset($relevantParts[$key]);
            } else {
                $relevantParts[$key] = ucfirst(trim($part));
            }
        }
        return implode('_', $relevantParts);
    }


}
