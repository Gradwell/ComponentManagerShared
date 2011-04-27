<?php

/**
 * Copyright (c) 2010 Gradwell dot com Ltd.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Gradwell dot com Ltd nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @package     Gradwell
 * @subpackage  ComponentManager
 * @author      Stuart Herbert <stuart.herbert@gradwell.com>
 * @copyright   2010 Gradwell dot com Ltd. www.gradwell.com
 * @license     http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @link        http://gradwell.github.com
 * @version     @@PACKAGE_VERSION@@
 */

namespace Gradwell\ComponentManager\PhixCommands;

use Phix_Project\Phix\CommandsList;
use Phix_Project\Phix\Context;
use Phix_Project\PhixExtensions\CommandBase;
use Phix_Project\PhixExtensions\CommandInterface;
use Gradwell\CommandLineLib\DefinedSwitches;
use Gradwell\CommandLineLib\DefinedSwitch;
use Gradwell\CommandLineLib\CommandLineParser;
use Gradwell\ValidationLib\MustBeValidFile;
use Gradwell\ValidationLib\MustBeValidPath;
use Gradwell\ValidationLib\MustBeWriteable;

if (!class_exists('Gradwell\ComponentManager\PhixCommands\ExpandPackageXml'))
{
class ExpandPackageXml extends CommandBase implements CommandInterface
{
        public function getCommandName()
        {
                return 'pear:expand-package-xml';
        }

        public function getCommandDesc()
        {
                return 'expand the tokens and contents of the PEAR-compatible package.xml file';
        }

        public function getCommandOptions()
        {
                $options = new DefinedSwitches();

                $options->addSwitch('properties', 'specify the build.properties file to use')
                        ->setWithShortSwitch('b')
                        ->setWithLongSwitch('build.properties')
                        ->setWithRequiredArg('<build.properties>', 'the path to the build.properties file to use')
                        ->setArgHasDefaultValueOf('build.properties')
                        ->setArgValidator(new MustBeValidFile());

                $options->addSwitch('packageXml', 'specify the package.xml file to expand')
                        ->setWithShortSwitch('p')
                        ->setWithLongSwitch('packageXml')
                        ->setwithRequiredArg('<package.xml>', 'the path to the package.xml file to use')
                        ->setArgHasDefaultValueOf('.build/package.xml')
                        ->setArgValidator(new MustBeValidFile())
                        ->setArgValidator(new MustBeWriteable());

                $options->addSwitch('srcFolder', 'specify the src folder to feed into package.xml')
                        ->setWithShortSwitch('s')
                        ->setWithLongSwitch('src')
                        ->setWithRequiredArg('<folder>', 'the path to the folder where the package source files are')
                        ->setArgHasDefaultValueOf('src')
                        ->setArgValidator(new MustBeValidPath());

                $options->addSwitch('packageFolder', 'specify the top-level folder of the package')
                        ->setWithShortSwitch('P')
                        ->setWithLongSwitch('packageFolder')
                        ->setWithRequiredArg('<folder>', 'thepath to the top-level folder of the package')
                        ->setArgHasDefaultValueOf('.')
                        ->setArgValidator(new MustBeValidPath());

                return $options;
        }

        public function validateAndExecute($args, $argsIndex, Context $context)
        {
                $so = $context->stdout;
                $se = $context->stderr;

                // step 1: parse the options
                $options  = $this->getCommandOptions();
                $parser   = new CommandLineParser();
                list($parsedSwitches, $argsIndex) = $parser->parseSwitches($args, $argsIndex, $options);

                // step 2: verify the args
                $errors = $parsedSwitches->validateSwitchValues();
                if (count($errors) > 0)
                {
                        // validation failed
                        foreach ($errors as $errorMsg)
                        {
                                $se->output($context->errorStyle, $context->errorPrefix);
                                $se->outputLine(null, $errorMsg);
                        }

                        // return the error code to the caller
                        return 1;
                }

                // step 3: extract the values we need to carry on
                // var_dump($parsedSwitches);

                $buildPropertiesFile = $parsedSwitches->getFirstArgForSwitch('properties');
                $packageXmlFile      = $parsedSwitches->getFirstArgForSwitch('packageXml');
                $srcFolder           = $parsedSwitches->getFirstArgForSwitch('srcFolder');
                $packageFolder       = $parsedSwitches->getFirstArgForSwitch('packageFolder');

                // step 4: let's get it on
                return $this->populatePackageXmlFile($context, $buildPropertiesFile, $packageXmlFile, $srcFolder, $packageFolder);
        }

        protected function populatePackageXmlFile(Context $context, $buildPropertiesFile, $packageXmlFile, $srcFolder, $packageFolder)
        {
                // load the files we are going to manipulate
                $rawBuildProperties = $this->loadBuildProperties($context, $buildPropertiesFile);
                $rawXml = $this->loadPackageXmlFile($context, $packageXmlFile);

                // translate the raw properties into the tokens we support
                $buildProperties = array();
                foreach ($rawBuildProperties as $name => $value)
                {
                        $buildProperties['${' . $name . '}'] = $value;
                }
                $buildProperties['${build.date}'] = date('Y-m-d');
                $buildProperties['${build.time}'] = date('H:i:s');

                // generate a list of the files to add
                $buildProperties['${contents}']   = $this->calculateFilesList($context, $srcFolder, $packageFolder);

                // do the replacement
                $newXml = str_replace(array_keys($buildProperties), $buildProperties, $rawXml);

                // write out the new file
                file_put_contents($packageXmlFile, $newXml);

                // all done
                return 0;
        }

        protected function loadBuildProperties(Context $context, $buildPropertiesFile)
        {
                // @TODO: error handling
                return parse_ini_file($buildPropertiesFile);
        }

        protected function loadPackageXmlFile(Context $context, $packageXmlFile)
        {
                // @TODO: error handling
                return file_get_contents($packageXmlFile);
        }

        protected function calculateFilesList(Context $context, $srcFolder, $packageFolder)
        {
                $return = '';

                $roles = array(
                        'bin'   => 'script',
                        'data'  => 'data',
                        'doc'   => 'doc',
                        'php'   => 'php',
                        'tests/unit-tests/php' => 'test',
                        'www'   => 'www'
                );

                $blacklist = array (
                        '.empty'        => true,
                        '.DS_Store'     => true,
                        '.svn'          => true
                );

                foreach ($roles as $dir => $role)
                {
                        $searchFolder = $srcFolder . DIRECTORY_SEPARATOR . $dir;

                        // do we have the folder in this project?
                        if (!is_dir($searchFolder))
                        {
                                // no we do not - bail
                                continue;
                        }

                        // yes we do - what is inside?
                        $objects = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($searchFolder));
                        foreach ($objects as $name => $direntry)
                        {
                                // skip all directories
                                if ($direntry->isDir())
                                {
                                        continue;
                                }

                                $filename = \str_replace($searchFolder, '', $direntry->getPathname());

                                // var_dump($filename);
                                
                                // skip all blacklisted files
                                if (isset($blacklist[basename($filename)]))
                                {
                                        continue;
                                }

                                $md5sum   = \md5(file_get_contents($direntry->getPathname()));
				$return .= '      <file baseinstalldir="/" md5sum="' . $md5sum . '" name="' . $filename . '" role="' . $role . '">';

				// do we need tasks for this file?
                                $return .= $this->getTasksForRole($role);

                                // close the tag
                                $return .= "      </file>\n";
                        }
                }

                // we also explicitly add any doc files that can be found
                // in the top-level folder
                $dh = dir($packageFolder);

                while ($filename = $dh->read())
                {
                        $fqFilename = $packageFolder . DIRECTORY_SEPARATOR . $filename;
                        // skip folders
                        if (is_dir($fqFilename))
                        {
                                continue;
                        }

                        // skip files that do not interest us
                        if (!preg_match('/.+\.txt$|.+\.md$/', $filename))
                        {
                                continue;
                        }

                        // if we get here, we want this file in our list
                        $md5sum   = \md5(file_get_contents($fqFilename));
                        $return .= '      <file baseinstalldir="/" md5sum="' . $md5sum . '" name="' . $filename . '" role="doc">';

                        // do we need tasks for this file?
                        $return .= $this->getTasksForRole('doc');

                        // close the tag
                        $return .= "      </file>\n";
                }

                // all done
                return $return;
        }

        protected function getTasksForRole($role)
        {
                $return = "";

                // IMPORTANT:
                //
                // We *deliberately* break up the @@ tokens below to
                // make sure that pear does not replace them when
                // this file is installed
                switch($role)
                {
                        case 'php':
                        case 'test':
                        case 'doc':
                                // do something here
                                $return .= '        <tasks:replace from="@' . '@PACKAGE_VERSION@@" to="version" type="package-info" />' . "\n"
                                        .  '        <tasks:replace from="@' . '@PHP_DIR@@" to="php_dir" type="pear-config" />' . "\n"
                                        .  '        <tasks:replace from="@' . '@DATA_DIR@@" to="data_dir" type="pear-config" />' . "\n";
                                break;

                        case 'script':
                                // do something here
                                $return .= '        <tasks:replace from="@' . '@PACKAGE_VERSION@@" to="version" type="package-info" />' . "\n"
                                        .  '        <tasks:replace from="/usr/bin/env php" to="php_bin" type="pear-config" />' . "\n"
                                        .  '        <tasks:replace from="@' . '@PHP_BIN@@" to="php_bin" type="pear-config" />' . "\n"
                                        .  '        <tasks:replace from="@' . '@BIN_DIR@@" to="bin_dir" type="pear-config" />' . "\n"
                                        .  '        <tasks:replace from="@' . '@PHP_DIR@@" to="php_dir" type="pear-config" />' . "\n"
                                        .  '        <tasks:replace from="@' . '@DATA_DIR@@" to="data_dir" type="pear-config" />' . "\n";
                                break;

                        default:
                                // do nothing
                }

                return $return;
        }
}
}