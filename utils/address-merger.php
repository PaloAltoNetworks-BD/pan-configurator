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
echo   "*********** ADDRESS-MERGER UTILITY **************\n\n";

set_include_path( get_include_path() . PATH_SEPARATOR . dirname(__FILE__).'/../');
require_once("lib/panconfigurator.php");


function display_usage_and_exit($shortMessage = false)
{
    global $argv;
    echo PH::boldText("USAGE: ")."php ".basename(__FILE__)." in=inputfile.xml out=outputfile.xml location=shared|vsys1|dg1 ".
        "\n";

    if( !$shortMessage )
    {
        echo PH::boldText("\nListing available arguments\n\n");

        global $supportedArguments;

        ksort($supportedArguments);
        foreach( $supportedArguments as &$arg )
        {
            echo " - ".PH::boldText($arg['niceName']);
            if( isset( $arg['argDesc']))
                echo '='.$arg['argDesc'];
            //."=";
            if( isset($arg['shortHelp']))
                echo "\n     ".$arg['shortHelp'];
            echo "\n\n";
        }

        echo "\n\n";
    }

    exit(1);
}

function display_error_usage_exit($msg)
{
    fwrite(STDERR, PH::boldText("\n**ERROR** ").$msg."\n\n");
    display_usage_and_exit(true);
}


$supportedArguments = Array();
$supportedArguments['in'] = Array('niceName' => 'in', 'shortHelp' => 'input file ie: in=config.xml', 'argDesc' => '[filename]');
$supportedArguments['out'] = Array('niceName' => 'out', 'shortHelp' => 'output file to save config after changes. Only required when input is a file. ie: out=save-config.xml', 'argDesc' => '[filename]');
$supportedArguments['location'] = Array('niceName' => 'Location', 'shortHelp' => 'specify if you want to limit your query to a VSYS/DG. By default location=shared for Panorama, =vsys1 for PANOS', 'argDesc' => '=vsys1|shared|dg1');
$supportedArguments['mergecountlimit'] = Array('niceName' => 'mergecountlimit', 'shortHelp' => 'stop operations after X objects have been merged', 'argDesc'=> '=100');
$supportedArguments['pickfilter'] =Array('niceName' => 'pickFilter', 'shortHelp' => 'specify a filter a pick which object will be kept while others will be replaced by this one', 'argDesc' => '=(name regex /^g/)');
$supportedArguments['allowmergingwithupperlevel'] =Array('niceName' => 'allowMergingWithUpperLevel', 'shortHelp' => 'when this argument is specified, it instructs the script to also look for duplicates in upper level');
$supportedArguments['help'] = Array('niceName' => 'help', 'shortHelp' => 'this message');

// load PAN-Configurator library
require_once("lib/panconfigurator.php");

PH::processCliArgs();

// check that only supported arguments were provided
foreach ( PH::$args as $index => &$arg )
{
    if( !isset($supportedArguments[$index]) )
    {
        if( strpos($index,'subquery') === 0 )
        {
            $nestedQueries[$index] = &$arg;
            continue;
        }
        //var_dump($supportedArguments);
        display_error_usage_exit("unsupported argument provided: '$index'");
    }
}

if( isset(PH::$args['help']) )
{
    display_usage_and_exit();
}

if( !isset(PH::$args['in']) )
    display_error_usage_exit(' "in=" argument is missing');

if( !isset(PH::$args['location']) )
    display_error_usage_exit(' "location=" argument is missing');

$location = PH::$args['location'];

if( isset(PH::$args['mergecountlimit']) )
    $mergeCountLimit = PH::$args['mergecountlimit'];
else
    $mergeCountLimit = false;


//
// What kind of config input do we have.
//     File or API ?
//
// <editor-fold desc="  ****  input method validation and PANOS vs Panorama auto-detect  ****" defaultstate="collapsed" >
$configInput = PH::processIOMethod(PH::$args['in'], true);
$xmlDoc = null;

if( $configInput['status'] == 'fail' )
{
    fwrite(STDERR, "\n\n**ERROR** " . $configInput['msg'] . "\n\n");exit(1);
}

if( $configInput['type'] == 'file' )
{
    $apiMode = false;
    if( !file_exists($configInput['filename']) )
        derr("file '{$configInput['filename']}' not found");

    $xmlDoc = new DOMDocument();
    echo " - Reading XML file from disk... ";
    if( ! $xmlDoc->load($configInput['filename']) )
        derr("error while reading xml config file");
    echo "OK!\n";

}
elseif ( $configInput['type'] == 'api'  )
{
    $apiMode = true;
    echo " - Downloading config from API... ";
    $xmlDoc = $configInput['connector']->getCandidateConfig();
    echo "OK!\n";
}
else
    derr('not supported yet');

//
// Determine if PANOS or Panorama
//
$xpathResult = DH::findXPath('/config/devices/entry/vsys', $xmlDoc);
if( $xpathResult === FALSE )
    derr('XPath error happened');
if( $xpathResult->length <1 )
    $configType = 'panorama';
else
    $configType = 'panos';
unset($xpathResult);


if( $configType == 'panos' )
    $panc = new PANConf();
else
    $panc = new PanoramaConf();

echo " - Detected platform type is '{$configType}'\n";

if( $configInput['type'] == 'api' )
    $panc->connector = $configInput['connector'];

//
// load the config
//
echo " - Loading configuration through PAN-Configurator library... ";
$loadStartMem = memory_get_usage(true);
$loadStartTime = microtime(true);
$panc->load_from_domxml($xmlDoc);
$loadEndTime = microtime(true);
$loadEndMem = memory_get_usage(true);
$loadElapsedTime = number_format( ($loadEndTime - $loadStartTime), 2, '.', '');
$loadUsedMem = convert($loadEndMem - $loadStartMem);
echo "OK! ($loadElapsedTime seconds, $loadUsedMem memory)\n";
// --------------------

// </editor-fold>


if( !$apiMode )
{
    if( !isset(PH::$args['out']) )
        display_error_usage_exit(' "out=" argument is missing');

    $outputfile = PH::$args['out'];

    // destroy destination file if it exists
    if( file_exists($outputfile) && is_file($outputfile) )
        unlink($outputfile);
}


if( $location == 'shared' )
{
    $store = $panc->addressStore;
    $parentStore = null;
}
else
{
    $findLocation = $panc->findSubSystemByName($location);
    if( $findLocation === null )
        derr("cannot find DeviceGroup/VSYS named '{$location}', check case or syntax");

    $store = $findLocation->addressStore;
    $parentStore = $findLocation->owner->addressStore;
}

if( $panc->isPanorama() )
{
    if( $location == 'shared' )
        $childDeviceGroups = $panc->deviceGroups;
    else
        $childDeviceGroups = $findLocation->childDeviceGroups(true);
}

$query = null;
if( isset(PH::$args['pickfilter']) )
{
    $query = new RQuery('address');
    $errMsg = '';
    if( $query->parseFromString(PH::$args['pickfilter'], $errMsg) === FALSE )
        derr("invalid pickFilter was input: ".$errMsg);
    echo " - pickFilter was input: ";
    $query->display();
    echo "\n";

}

$upperLevelSearch = false;
if( isset(PH::$args['allowmergingwithupperlevel']) )
    $upperLevelSearch = true;

echo " - upper level search status : ".boolYesNo($upperLevelSearch)."\n";
echo " - location '{$location}' found\n";
echo " - found {$store->countAddresses()} address Objects\n";
echo " - computing address values database ... ";
sleep(1);

//
// Building a hash table of all address objects with same value
//
if( $upperLevelSearch)
    $objectsToSearchThrough = $store->nestedPointOfView();
else
    $objectsToSearchThrough = $store->addressObjects();

$hashMap = Array();
$upperHashMap = Array();
foreach( $objectsToSearchThrough as $object )
{
    if( !$object->isAddress() )
        continue;
    if( $object->isTmpAddr() )
        continue;

    $skipThisOne = FALSE;

    // Object with descendants in lower device groups should be excluded
    if( $panc->isPanorama() && $object->owner === $store )
    {
        foreach( $childDeviceGroups as $dg )
        {
            if( $dg->addressStore->find($object->name(), null, FALSE) !== null )
            {
                $skipThisOne = TRUE;
                break;
            }
        }
        if( $skipThisOne )
            continue;
    }

    $value = $object->value();

    // if object is /32, let's remove it to match equivalent non /32 syntax
    if( $object->isType_ipNetmask() && strpos($object->value(), '/32') !== FALSE )
        $value = substr($value, 0, strlen($value) - 3);

    $value = $object->type() . '-' . $value;

    if( $object->owner === $store )
    {
        $hashMap[$value][] = $object;
        if( $parentStore !== null )
        {
            $findAncestor = $parentStore->find($object->name(), null, true);
            if( $findAncestor !== null )
                $object->ancestor = $findAncestor;
        }
    }
    else
        $upperHashMap[$value][] = $object;
}

//
// Hashes with single entries have no duplicate, let's remove them
//
$countConcernedObjects = 0;
foreach( $hashMap as $index => &$hash )
{
    if( count($hash) == 1 && !isset($upperHashMap[$index]) && !isset(reset($hash)->ancestor) )
        unset($hashMap[$index]);
    else
        $countConcernedObjects += count($hash);
}
unset($hash);
echo "OK!\n";

echo " - found ".count($hashMap)." duplicates values totalling {$countConcernedObjects} address objects which are duplicate\n";

echo "\n\nNow going after each duplicates for a replacement\n";

$countRemoved = 0;
foreach( $hashMap as $index => &$hash )
{
    echo "\n";
    echo " - value '{$index}'\n";

    $pickedObject = null;

    if( $query !== null )
    {
        if( isset($upperHashMap[$index]) )
        {
            foreach( $upperHashMap[$index] as $object )
            {
                if( $query->matchSingleObject($object) )
                {
                    $pickedObject = $object;
                    break;
                }
            }
            if( $pickedObject === null )
                $pickedObject = reset($upperHashMap[$index]);

            echo "   * using object from upper level : '{$pickedObject->name()}'\n";
        }
        else
        {
            foreach( $hash as $object )
            {
                if( $query->matchSingleObject($object) )
                {
                    $pickedObject = $object;
                    break;
                }
            }
            if( $pickedObject === null )
                $pickedObject = reset($hash);

            echo "   * keeping object '{$pickedObject->name()}'\n";
        }
    }
    else
    {
        if( isset($upperHashMap[$index]) )
        {
            $pickedObject = reset($upperHashMap[$index]);
            echo "   * using object from upper level : '{$pickedObject->name()}'\n";
        }
        else
        {
            $pickedObject = reset($hash);
            echo "   * keeping object '{$pickedObject->name()}'\n";
        }
    }


    // Merging loop finally!
    foreach( $hash as $objectIndex => $object)
    {
        /** @var Address $object */
        if( isset($object->ancestor) )
        {
            $ancestor = $object->ancestor;
            /** @var Address $ancestor */
            if( $upperLevelSearch && !$ancestor->isTmpAddr() && ($ancestor->isType_ipNetmask()||$ancestor->isType_ipRange()) )
            {
                if( $object->getIP4Mapping()->equals($ancestor->getIP4Mapping()) )
                {
                    echo "    - object '{$object->name()}' merged with its ancestor, deleting this one... ";
                    $object->replaceMeGlobally($ancestor);
                    if( $apiMode )
                        $object->owner->API_remove($object);
                    else
                        $object->owner->remove($object);

                    echo "OK!\n";

                    if( $pickedObject === $object )
                        $pickedObject = $ancestor;

                    $countRemoved++;

                    if( $mergeCountLimit !== FALSE && $countRemoved >= $mergeCountLimit )
                    {
                        echo "\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$mergeCountLimit})\n";
                        break 2;
                    }

                    continue;
                }
            }
            echo "    - object '{$object->name()}' cannot be merged because it has an ancestor\n";
            continue;
        }

        if( $object === $pickedObject )
            continue;

        echo "    - replacing '{$object->name()}'\n";
        if( $apiMode )
        {
            $object->API_addObjectWhereIamUsed( $pickedObject, true, 6);
            $object->API_removeWhereIamUsed( true, 6);
            $object->owner->API_remove($object);
        }
        else
        {
            $object->addObjectWhereIamUsed( $pickedObject, true, 6);
            $object->removeWhereIamUsed( true, 6);
            $object->owner->remove($object);
        }
        $countRemoved++;

        if( $mergeCountLimit !== FALSE && $countRemoved >= $mergeCountLimit )
        {
            echo "\n *** STOPPING MERGE OPERATIONS NOW SINCE WE REACHED mergeCountLimit ({$mergeCountLimit})\n";
            break 2;
        }
    }
}

echo "\n\nDuplicates removal is now done. Number of objects after cleanup: '{$store->countAddresses()}' (removed {$countRemoved} addresses)\n\n";

echo "\n\n***********************************************\n\n";

echo "\n\n";

if( !$apiMode )
    $panc->save_to_file($outputfile);

echo "\n************* END OF SCRIPT ".basename(__FILE__)." ************\n\n";



