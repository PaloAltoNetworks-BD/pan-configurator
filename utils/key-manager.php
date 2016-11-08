<?php

/*
 * Copyright (c) 2014-2015 Palo Alto Networks, Inc. <info@paloaltonetworks.com>
 * Author: Christophe Painchaud <cpainchaud _AT_ paloaltonetworks.com>
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.

 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
*/


echo "\n***********************************************\n";
echo   "*********** ".basename(__FILE__)." UTILITY **************\n\n";

set_include_path( dirname(__FILE__).'/../'. PATH_SEPARATOR . get_include_path() );
require_once("lib/panconfigurator.php");
require_once(dirname(__FILE__).'/common/misc.php');



$supportedArguments = Array();
$supportedArguments[] = Array('niceName' => 'delete', 'shortHelp' => 'Clears API key for hostname/IP provided as an argument.', 'argDesc' => '[hostname or IP]');
$supportedArguments[] = Array('niceName' => 'help', 'shortHelp' => 'this message');

$usageMsg = PH::boldText('USAGE: ')."php ".basename(__FILE__)." [delete=hostOrIP]";

prepareSupportedArgumentsArray($supportedArguments);
PH::processCliArgs();

// check that only supported arguments were provided
foreach ( PH::$args as $index => &$arg )
{
    if( !isset($supportedArguments[$index]) )
    {
        display_error_usage_exit("unsupported argument provided: '$index'");
    }
}

echo " - loading keystore from file in user home directory... ";
PanAPIConnector::loadConnectorsFromUserHome();
echo "OK!\n";

echo "\n";

$noArgProvided = true;

if( isset(PH::$args['delete']) )
{
    $noArgProvided = false;
    $deleteHost = PH::$args['delete'];
    echo " - requested to delete Host/IP '{$deleteHost}'\n";
    if( !is_string($deleteHost) )
        derr("argument of 'delete' must be a string , wrong input provided");

    $foundConnector = false;
    foreach(PanAPIConnector::$savedConnectors as $cIndex => $connector)
    {
        if( $connector->apihost == $deleteHost )
        {
            $foundConnector = true;
            echo " - found and deleted\n\n";
            unset(PanAPIConnector::$savedConnectors[$cIndex]);
            PanAPIConnector::saveConnectorsToUserHome();
        }
    }
    if( !$foundConnector )
        echo "\n\n **WARNING** no host or IP named '{$deleteHost}' was found so it could not be deleted\n\n";
}

$keyCount = count(PanAPIConnector::$savedConnectors);
echo "Listing available keys:\n";

$connectorList = Array();
foreach(PanAPIConnector::$savedConnectors as $connector)
{
    $connectorList[$connector->apihost] = $connector;
}
ksort($connectorList);

foreach($connectorList as $connector)
{
    $key = $connector->apikey;
    if( strlen($key) > 24 )
        $key = substr($key, 0, 12).'...'.substr($key, strlen($key)-12);
    $host = str_pad($connector->apihost, 15, ' ', STR_PAD_RIGHT);

    echo " - Host {$host}: key={$key}\n";
}

if( $noArgProvided )
{
    print "\n";
    display_usage_and_exit();
}

echo "\n************* END OF SCRIPT ".basename(__FILE__)." ************\n\n";



