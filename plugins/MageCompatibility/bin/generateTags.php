<?php
/**
 * Generate tag file for use with MageCompatibility plugin for Judge
 */

if (2 != count($argv)) {
    die('Please submit exactly one param: The path to the Magento app directory' . PHP_EOL);
}
$pathToMagentoAppDir = $argv[1];
if (substr($pathToMagentoAppDir, -1) != '/') {
    $pathToMagentoAppDir .= '/';
}
if (substr($pathToMagentoAppDir, -5) != '/app/') {
    die('Please submit exactly one param: The path to the Magento app directory, which should endup with "/app/".' . PHP_EOL);
}
if (false === file_exists($pathToMagentoAppDir . 'Mage.php')) {
    die('Are you sure, there is a Magento? Couldn\'t find Mage.php!' . PHP_EOL);
}

$tagger = new Tagger($pathToMagentoAppDir);
$tagger->run();

class Tagger
{
    protected $tagDir;
    protected $pathToMagentoAppDir;
    protected $edition;
    protected $version;

    public function __construct($pathToMagentoAppDir)
    {
        $this->tagDir = dirname(__FILE__) . '/../var/tags/';
        $this->pathToMagentoAppDir = $pathToMagentoAppDir;
        $this->verifyMagento($pathToMagentoAppDir);
    }

    protected function verifyMagento($pathToMagentoAppDir)
    {
        include $pathToMagentoAppDir . 'Mage.php';

        $this->edition = (method_exists('Mage', 'getEdition')) ? Mage::getEdition() : 'Community';
        $this->version = Mage::getVersion();
        echo 'Analyzing Magento ' . $this->version . ' (' . $this->edition . ' Edition)...' . PHP_EOL;
    }

    protected function getTagFileName()
    {
        return strtolower($this->edition) . '-' . $this->version . '.tags';
    }

    protected function getRawTagFilePath()
    {
        return $this->tagDir . $this->getTagFileName() . '.raw';
    }

    public function run()
    {
        $command = sprintf(
            'cd %s && ctags -R --languages=php --totals=yes --tag-relative=yes --PHP-kinds=+cf-v -h ".ph" --fields=+n -f tags .',
            $this->pathToMagentoAppDir,
            $this->pathToMagentoAppDir,
            $this->edition,
            $this->version
        );

        exec($command, $output);

        rename($this->pathToMagentoAppDir . 'tags', $this->getRawTagFilePath());

        $rawTagFile = fopen($this->getRawTagFilePath(), 'r');
        $tagFile = fopen($this->tagDir . $this->getTagFileName(), 'w');
        $tagFileLineNumber = 0;
        while ($line = fgets($rawTagFile)) {
            ++$tagFileLineNumber;
            if ('!_T' == substr($line, 0,3)) {
                // skip comment lines
                continue;
            }
            list($tag, $path, $codeLine, $type, $sourceLineNumber) = explode("\t", $line);

            if ('j' == $type) {
                // skip js
                continue;
            }

            preg_match ('/,\\$\//', $codeLine, $incompleteLine);
            if (count($incompleteLine)) {
                $codeLine = $this->getCompleteFunctionDefinition($path, $tag, $sourceLineNumber);
            }

            fputs($tagFile, implode(
                "\t",
                array($tag, $path, $codeLine, $type, $sourceLineNumber)
            ));
        }
        fclose($rawTagFile);
        unlink($this->getRawTagFilePath());
    }

    protected function getCompleteFunctionDefinition($path, $tag, $sourceLineNumber)
    {
        $sourceLineNumber = (int) trim(str_replace('line:', '', $sourceLineNumber));
        $sourceFile = fopen($this->pathToMagentoAppDir . $path, 'r');
        $currentLineNumber = 0;
        while(!feof($sourceFile))
        {
            ++$currentLineNumber;
            $line = fgets($sourceFile);
            if ($sourceLineNumber == $currentLineNumber) {
                $functionDefinition = '/^' . str_replace("\n", '', $line);
            }
            if ($sourceLineNumber < $currentLineNumber) {
                $bodyStartPos = strpos($line, '{');
                if (false !== $bodyStartPos) {
                    $line = substr($line, 0, $bodyStartPos);
                }
                $functionDefinition .= ' ' . trim($line);
                if (substr_count($line, '(') < substr_count($line, ')')) {
                    $functionDefinition = trim($functionDefinition) . '$/;"';
                    break;
                }
            }
        }
        fclose($sourceFile);

        return $functionDefinition;
    }
}
