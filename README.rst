=====
Judge
=====

Judge is a tool to examine Magento extensions regarding their qualitiy and compatibility.

Prerequsites
============

Judge requires some PEAR packages to be installed:

* PHP_CompatInfo

  ::

    $ sudo pear channel-discover bartlett.laurent-laville.org
    Adding Channel "bartlett.laurent-laville.org" succeeded
    Discovery of channel "bartlett.laurent-laville.org" succeeded

    $ sudo pear channel-discover components.ez.no
    Adding Channel "components.ez.no" succeeded
    Discovery of channel "components.ez.no" succeeded

    $ sudo pear channel-discover pear.phpunit.de
    Adding Channel "pear.phpunit.de" succeeded
    Discovery of channel "pear.phpunit.de" succeeded

    $ sudo pear install bartlett/PHP_CompatInfo

* TheSeer/fDOMDocument

  ::

    $ sudo pear channel-discover pear.netpirates.net
    $ sudo pear install TheSeer/fDOMDocument

Installation
============

First of all you need Judge itself, of course. So you'll have to clone it recursively:

::

    git clone git@gitorious.nr:tools/judge.git

Judge requires a lot of tools to evaluate extensions. For dependency management, we use Composer_. So you'll have to
install it on your system:

::

    cd judge
    curl -s http://getcomposer.org/installer | php

.. _Composer: http://getcomposer.org/

Now you're ready to fetch all the required dependencies:

::

    php composer.phar install

You should see something like that:

::

    Installing dependencies
      - Installing symfony/yaml (v2.1.4)

      - Installing phpunit/php-text-template (1.1.4)

      - Installing phpunit/phpunit-mock-objects (1.2.2)

      - Installing phpunit/php-timer (1.0.4)

      - Installing phpunit/php-token-stream (1.1.5)

      - Installing phpunit/php-file-iterator (1.3.3)

      - Installing phpunit/php-code-coverage (1.2.7)

      - Installing phpunit/phpunit (3.7.10)

      - Installing pdepend/pdepend (dev-master 981b7c9)
        Cloning 981b7c9c831dc489523c5034729eba7297fa424e

      - Installing nikic/php-parser (dev-master v0.9.3)
        Cloning v0.9.3

      - Installing phpmd/phpmd (dev-master 34cfbf3)
        Cloning 34cfbf382dd2a62b2bb3573a9fb3737c8f5a0eec

      - Installing squizlabs/php_codesniffer (dev-master c051e90)
        Cloning c051e90d483a9a0c83d61381d5a3625da98f2d6a

      - Installing dg/dibi (dev-master 8cda140)
        Cloning 8cda1401ffc0db28da89bb132cae315995dc73f5

      - Installing symfony/class-loader (2.0.x-dev v2.0.19)
        Cloning v2.0.19

      - Installing symfony/console (2.0.x-dev v2.0.19)
        Cloning v2.0.19

      - Installing netresearch/jumpstorm (dev-master 7735cf1)
        Cloning 7735cf15bc166ea37c6aecceecdbe60d9ecccbce

      - Installing mthaml/mthaml (dev-master 0c02a75)
        Cloning 0c02a75c791e9f4deed981483c041a96badaf5ad

      - Installing pear-pear.phpunit.de/file_iterator (1.3.3)
      - Installing pear-pear.phpunit.de/phpcpd (1.2.2)
    phpunit/phpunit suggests installing phpunit/php-invoker (>=1.1.0)
    Writing lock file
    Generating autoload files

*That's all. Happy judging :)*

Usage
=====

To evaluate an extension, you simply call

::

    judge evaluate /path/to/extension

and you will get a summary report after a while.

There are some command line options available:

.. list-table:: Judge command line parameters
   :widths: 1 3
   :header-rows: 1

   * - parameter
     - description

   * - --config (-c)
     - provide a configuration file (default: 'ini/sample.judge.ini')

   * - --verbose (-v)
     - Increase output verbosity

Prerequisites
-------------

Judge obtains information on various Magento versions from a database that needs
to be created before running the tool. Restore the database dump from
`judge.sql.zip` (included in the root directory) and set your database
credentials via Configuration_.

Configuration
-------------

Judge comes with a sample configuration file, which resides at
`ini/sample.judge.ini`. The most relevant configuration part is ``[plugins]``,
where you can adjust tools, measures and other special settings for all evaluations.

Quality Checks
==============

CheckComments
-------------
This check evaluates the extension's code comment ratio.

CheckStyle
----------
Checks, if the extension follows the Magento coding guidelines.

CodeCoverage
------------
Runs unit tests (if available) and calculates their coverage.

CodeRuin
--------
Detect unfinished parts of code.

CoreHacks
---------
Detect if the extension uses include hacks to override Magento core components.

MageCompatibility
-----------------
Try to find compatible Magento version. This is a very tricky task, since Magento uses a lot of Magic.

The extension gets parsed and all class dependencies, method calls and constants usage will be compared
to all Magento versions (although we currently check only CE 1.3.2.4-1.7.0.2 and EE 1.8.0.0-1.10.1.1).
We extracted all these tokens from the different Magento versions and inserted them in the database shipped with Judge.
The tokens represent
* existing classes
* existing constants
* existing methods
* magic get/set/has/uns for database fields (although we may not detect them all).

We know, that there are a lot of false alarms, especially due to magic get/set/has/uns that also exist in code in some Magento versions. So here is a lot of work to do.

There are some very hard nuts: For instance, ``Varien_Data_Form_Element_Abstract`` supports calling ``getOriginalData``,
but that is done by a magic getter. Since it is a form element, there is no database representation for this property
and so our scripts did not recognize that.
That's why we introduced a JSON file (``plugins/MageCompatibility/var/fixedVersions.json``), where you can add tokens you know
to be supported by some specific version.

PerformanceCheck
----------------
Try to find some well-known performance killers.

PhpCompatibility
----------------
Detect the minimum required PHP version to run the extension.

Rewrites
--------
Count rewrites of the extension. The more rewrites an extension includes, the less compatibility to other extensions can be expected.

SecurityCheck
-------------
Try to find some well-known security leaks.

SourceCodeComplexity
--------------------
Calculates the source code complexity.

Architecture
============

Judge is based on the Jumpstorm_ architecture, which is very flexible, so that every component could be replaced by
another one. So it should be no problem to use another Logger or even to provide a web interface (although the least
should not be possible for Jumpstorm that easy...).

.. _Jumpstorm: https://github.com/netresearch/jumpstorm

Every single check is made by a Judge plugin, which in most cases calls an external tool via ``exec()``.
