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

    git clone git@gitorious.nr:tools/judge.git --recursive

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
      - Installing symfony/finder (v2.0.16)
        Downloading: 100%         

      - Installing eher/phpunit (dev-master)
        Cloning 5129a677f272d49f6aee8223aa3a1b5fed2a53b7

      - Installing pdepend/pdepend (dev-master)
        Cloning f62a21752b5de843c329065641b3034aad59bce7

      - Installing phpmd/phpmd (dev-master)
        Cloning efa8b0958f2174a4ccaf635f5225258b6cb36ea1

      - Installing zerkalica/php_codesniffer (dev-master)
        Cloning a2c210f57d478697b0746bbd2bc76fccfda8a3b4

    Writing lock file
    Generating autoload files

Though that is a bit strange, you probably need to call

::

    cd vendor/EHER/PHPUnit && ./build.sh && cd -

to fetch some further dependencies...

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

Configuration
-------------

Judge comes with a sample configuration file, which resides at `ini/sample.judge.ini`. The most relevant configuration
part is ``[plugins]``, where you can adjust tools, measures and other special settings for all evaluations.

Architecture
============

Judge is based on the Jumpstorm_ architecture, which is very flexible, so that every component could be replaced by
another one. So it should be no problem to use another Logger or even to provide a web interface (although the least
should not be possible for Jumpstorm that easy...).

.. _Jumpstorm: https://github.com/netresearch/jumpstorm

Every single check is made by a Judge plugin, which in most cases calls an external tool via ``exec()``.
