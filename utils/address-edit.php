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
echo   "*********** ADDRESS-EDIT UTILITY **************\n\n";

set_include_path( dirname(__FILE__).'/../'. PATH_SEPARATOR . get_include_path() );
require_once("lib/panconfigurator.php");
require_once("common/actions.php");


function display_usage_and_exit($shortMessage = false)
{
    global $argv;
    echo PH::boldText("USAGE: ")."php ".basename(__FILE__)." in=inputfile.xml out=outputfile.xml location=all|shared|sub ".
        "actions=action1:arg1 ['filter=(type is.group) or (name contains datacenter-)']\n";
    echo "php ".basename(__FILE__)." listactions   : list supported actions\n";
    echo "php ".basename(__FILE__)." listfilters   : list supported filter\n";
    echo "php ".basename(__FILE__)." help          : more help messages\n";
    echo PH::boldText("\nExamples:\n");
    echo " - php ".basename(__FILE__)." type=panorama in=api://192.169.50.10 location=DMZ-Firewall-Group actions=displayReferences 'filter=(name eq Mail-Host1)'\n";
    echo " - php ".basename(__FILE__)." type=panos in=config.xml out=output.xml location=any actions=delete\n";

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


echo "\n";

$configType = null;
$configInput = null;
$configOutput = null;
$doActions = null;
$dryRun = false;
$objectsLocation = 'shared';
$objectsFilter = null;
$errorMessage = '';
$debugAPI = false;



$supportedArguments = Array();
$supportedArguments['in'] = Array('niceName' => 'in', 'shortHelp' => 'input file or api. ie: in=config.xml  or in=api://192.168.1.1 or in=api://0018CAEC3@panorama.company.com', 'argDesc' => '[filename]|[api://IP]|[api://serial@IP]');
$supportedArguments['out'] = Array('niceName' => 'out', 'shortHelp' => 'output file to save config after changes. Only required when input is a file. ie: out=save-config.xml', 'argDesc' => '[filename]');
$supportedArguments['location'] = Array('niceName' => 'Location', 'shortHelp' => 'specify if you want to limit your query to a VSYS/DG. By default location=shared for Panorama, =vsys1 for PANOS. ie: location=any or location=vsys2,vsys1', 'argDesc' => '=sub1[,sub2]');
$supportedArguments['listactions'] = Array('niceName' => 'ListActions', 'shortHelp' => 'lists available Actions');
$supportedArguments['listfilters'] = Array('niceName' => 'ListFilters', 'shortHelp' => 'lists available Filters');
$supportedArguments['stats'] = Array('niceName' => 'Stats', 'shortHelp' => 'display stats after changes');
$supportedArguments['actions'] = Array('niceName' => 'Actions', 'shortHelp' => 'action to apply on each rule matched by Filter. ie: actions=from-Add:net-Inside,netDMZ', 'argDesc' => 'action:arg1[,arg2]' );
$supportedArguments['debugapi'] = Array('niceName' => 'DebugAPI', 'shortHelp' => 'prints API calls when they happen');
$supportedArguments['filter'] = Array('niceName' => 'Filter', 'shortHelp' => "filters objects based on a query. ie: 'filter=((from has external) or (source has privateNet1) and (to has external))'", 'argDesc' => '(field operator [value])');
$supportedArguments['loadplugin'] = Array('niceName' => 'loadPlugin', 'shortHelp' => 'a PHP file which contains a plugin to expand capabilities of this script');
$supportedArguments['help'] = Array('niceName' => 'help', 'shortHelp' => 'this message');


$supportedActions = Array();
// <editor-fold desc="  ****  Supported Actions Array  ****" defaultstate="collapsed" >


$supportedActions['delete'] = Array(
    'name' => 'delete',
    'MainFunction' => function ( AddressCallContext $context )
    {
        $object = $context->object;

        if( $object->countReferences() != 0 )
        {
            print $context->padding."  * SKIPPED: this object is used by other objects and cannot be deleted (use deleteForce to try anyway)\n";
            return;
        }

        if( $context->isAPI )
            $object->owner->API_remove($object);
        else
            $object->owner->remove($object);
    },
);

$supportedActions['delete-force'] = Array(
    'name' => 'delete-Force',
    'MainFunction' => function ( AddressCallContext $context )
    {
        $object = $context->object;

        if( $object->countReferences() != 0 )
        {
            print $context->padding."  * WARNING : this object seems to be used so deletion may fail.\n";
        }

        if( $context->isAPI )
            $object->owner->API_remove($object);
        else
            $object->owner->remove($object);
    },
);

$supportedActions['replace-ip-by-mt-like-object'] = Array(
    'name' => 'replace-IP-by-MT-like-Object',
    'MainFunction' => function ( AddressCallContext $context )
    {
        $object = $context->object;

        if( !$object->isTmpAddr() )
        {
            echo $context->padding."     *  SKIPPED because object is not temporary or not an IP address/netmask\n";
            return;
        }

        $rangeDetected = false;

        if( !$object->nameIsValidRuleIPEntry() )
        {
            echo $context->padding . "     *  SKIPPED because object is not an IP address/netmask or range\n";
            return;
        }

        $objectRefs = $object->getReferences();
        $clearForAction = true;
        foreach( $objectRefs as $objectRef )
        {
            $class = get_class($objectRef);
            if( $class != 'AddressRuleContainer' && $class != 'NatRule' )
            {
                $clearForAction = false;
                echo $context->padding."     *  SKIPPED because its used in unsupported class $class\n";
                return;
            }
        }

        $pan = PH::findRootObjectOrDie($object->owner);

        if( strpos($object->name(), '-') === FALSE )
        {
            $explode = explode('/',$object->name());

            if( count($explode) > 1 )
            {
                $name = $explode[0];
                $mask = $explode[1];
            }
            else
            {
                $name = $object->name();
                $mask = 32;
            }

            if( $mask > 32 || $mask < 0 )
            {
                echo $context->padding."    * SKIPPED because of invalid mask detected : '$mask'\n";
                return;
            }

            if( filter_var($name, FILTER_VALIDATE_IP) === FALSE )
            {
                echo $context->padding."    * SKIPPED because of invalid IP detected : '$name'\n";
                return;
            }

            if( $mask == 32 )
            {
                $newName = 'H-'.$name;
            }
            else
            {
                $newName = 'N-'.$name.'-'.$mask;
            }
        }
        else
        {
            $rangeDetected = true;
            $explode= explode('-', $object->name());
            $newName = "R-".$explode[0].'-'.$explode[1];
        }

        echo $context->padding."    * new object name will be $newName\n";

        $objToReplace = $object->owner->find($newName);
        if( $objToReplace === null )
        {
            if( $context->isAPI )
            {
                if( $rangeDetected)
                    $objToReplace = $object->owner->API_newAddress($newName, 'ip-range', $explode[0].'-'.$explode[1] );
                else
                    $objToReplace = $object->owner->API_newAddress($newName, 'ip-netmask', $name.'/'.$mask);
            }
            else
            {
                if( $rangeDetected)
                    $objToReplace = $object->owner->newAddress($newName, 'ip-range', $explode[0].'-'.$explode[1] );
                else
                    $objToReplace = $object->owner->newAddress($newName, 'ip-netmask', $name.'/'.$mask);
            }
        }
        else
        {
            $objMap = IP4Map::mapFromText($name.'/'.$mask);
            if( !$objMap->equals($objToReplace->getIP4Mapping()) )
            {
                echo "    * SKIPPED because an object with same name exists but has different value\n";
                return;
            }
        }


        if( $clearForAction )
        {
            foreach( $objectRefs as $objectRef )
            {
                $class = get_class($objectRef);

                if( $class == 'AddressRuleContainer' )
                {
                    /** @var AddressRuleContainer $objectRef */
                    echo $context->padding."     - replacing in {$objectRef->toString()}\n";

                    if( $objectRef->owner->isNatRule()
                        && $objectRef->name == 'snathosts'
                        && $objectRef->owner->sourceNatTypeIs_DIPP()
                        && $objectRef->owner->snatinterface !== null )
                    {
                        echo $context->padding."        -  SKIPPED because it's a SNAT with Interface IP address\n";
                        continue;
                    }


                    if( $context->isAPI )
                        $objectRef->API_add($objToReplace);
                    else
                        $objectRef->addObject($objToReplace);

                    if( $context->isAPI )
                        $objectRef->API_remove($object);
                    else
                        $objectRef->remove($object);
                }
                elseif( $class == 'NatRule' )
                {
                    /** @var NatRule $objectRef */
                    echo $context->padding."     - replacing in {$objectRef->toString()}\n";

                    if( $context->isAPI )
                        $objectRef->API_setDNAT($objToReplace, $objectRef->dnatports);
                    else
                        $objectRef->replaceReferencedObject($object, $objToReplace);
                }
                else
                {
                    derr("unsupported class '$class'");
                }

            }
        }
    },
);

$supportedActions['removewhereused'] = Array(
    'name' => 'removeWhereUsed',
    'MainFunction' => function ( AddressCallContext $context )
    {
        $object = $context->object;

        if( $context->isAPI )
            $object->API_removeWhereIamUsed(true, $context->padding, $context->arguments['actionIfLastMemberInRule']);
        else
            $object->removeWhereIamUsed(true, $context->padding, $context->arguments['actionIfLastMemberInRule']);
    },
    'args' => Array( 'actionIfLastMemberInRule' => Array(   'type' => 'string',
        'default' => 'delete',
        'choices' => Array( 'delete', 'disable', 'setAny' )
    ),
    ),
);

$supportedActions['addobjectwhereused'] = Array(
    'name' => 'addObjectWhereUsed',
    'MainFunction' => function ( AddressCallContext $context )
    {
        $object = $context->object;
        $foundObject = $object->owner->find($context->arguments['objectName']);

        if( $foundObject === null )
            derr("cannot find an object named '{$context->arguments['objectName']}'");

        if( $context->isAPI )
            $object->API_addObjectWhereIamUsed($foundObject, true, $context->padding.'  ', false, $context->arguments['skipNatRules']);
        else
            $object->addObjectWhereIamUsed($foundObject, true, $context->padding.'  ', false, $context->arguments['skipNatRules']);
    },
    'args' => Array( 'objectName' => Array( 'type' => 'string', 'default' => '*nodefault*' ),
                     'skipNatRules' => Array( 'type' => 'bool', 'default' => false ) )
);

$supportedActions['replacewithobject'] = Array(
    'name' => 'replaceWithObject',
    'MainFunction' => function ( AddressCallContext $context )
    {
        $object = $context->object;
        $objectRefs = $object->getReferences();

        $foundObject = $object->owner->find($context->arguments['objectName']);

        if( $foundObject === null )
            derr("cannot find an object named '{$context->arguments['objectName']}'");

        /** @var AddressGroup|AddressRuleContainer $objectRef */

        foreach ($objectRefs as $objectRef)
        {
            echo $context->padding." * replacing in {$objectRef->toString()}\n";
            if( $context->isAPI )
                $objectRef->API_replaceReferencedObject($object, $foundObject);
            else
                $objectRef->replaceReferencedObject($object, $foundObject);
        }

    },
    'args' => Array( 'objectName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);

$supportedActions['z_beta_summarize'] = Array(
    'name' => 'z_BETA_summarize',
    'MainFunction' => function ( AddressCallContext $context )
    {
        $object = $context->object;

        if( !$object->isGroup() )
        {
            echo $context->padding."    - SKIPPED because object is not a group\n";
            return;
        }
        if( $object->isDynamic() )
        {
            echo $context->padding."    - SKIPPED because group is dynamic\n";
            return;
        }

        /** @var AddressGroup $object */
        $members = $object->expand();
        $mapping = new IP4Map();

        $listOfNotConvertibleObjects = Array();

        foreach($members as $member )
        {
            if( $member->isGroup() )
                derr('this is not supported');
            if( $member->type() == 'fqdn' )
            {
                $listOfNotConvertibleObjects[] = $member;
            }

            $mapping->addMap( $member->getIP4Mapping(), true );
        }
        $mapping->sortAndRecalculate();

        $object->removeAll();
        foreach($listOfNotConvertibleObjects as $obj )
            $object->addMember($obj);

        foreach($mapping->getMapArray() as $entry )
        {
            $objectName = 'R-'.long2ip($entry['start']).'-'.long2ip($entry['start']);
            $newObject = $object->owner->find($objectName);
            if( $newObject === null )
                $newObject = $object->owner->newAddress($objectName, 'ip-range', long2ip($entry['start']).'-'.long2ip($entry['start']));
            $object->addMember($newObject);
        }

        echo $context->padding."  - group had ".count($members)." expanded members vs {$mapping->count()} IP4 entries and ".count($listOfNotConvertibleObjects)." unsupported objects\n";

    },
);


$supportedActions['exporttoexcel'] = Array(
    'name' => 'exportToExcel',
    'MainFunction' => function(AddressCallContext $context)
    {
        $object = $context->object;
        $context->objectList[] = $object;
    },
    'GlobalInitFunction' => function(AddressCallContext $context)
    {
        $context->objectList = Array();
    },
    'GlobalFinishFunction' => function(AddressCallContext $context)
    {
        $args = &$context->arguments;
        $filename = $args['filename'];

        $lines = '';
        $encloseFunction  = function($value, $nowrap = true)
        {
            if( is_string($value) )
                $output = htmlspecialchars($value);
            elseif( is_array($value) )
            {
                $output = '';
                $first = true;
                foreach( $value as $subValue )
                {
                    if( !$first )
                    {
                        $output .= '<br />';
                    }
                    else
                        $first= false;

                    if( is_string($subValue) )
                        $output .= htmlspecialchars($subValue);
                    else
                        $output .= htmlspecialchars($subValue->name());
                }
            }
            else
                derr('unsupported');

            if( $nowrap )
                return '<td style="white-space: nowrap">'.$output.'</td>';

            return '<td>'.$output.'</td>';
        };

        $count = 0;
        if( isset($context->objectList) )
        {
            foreach ($context->objectList as $object)
            {
                $count++;

                /** @var Address|AddressGroup $object */
                if ($count % 2 == 1)
                    $lines .= "<tr>\n";
                else
                    $lines .= "<tr bgcolor=\"#DDDDDD\">";

                if ($object->owner->owner->isPanorama() || $object->owner->owner->isFirewall())
                    $lines .= $encloseFunction('shared');
                else
                    $lines .= $encloseFunction($object->owner->owner->name());

                $lines .= $encloseFunction($object->name());

                if( $object->isGroup() )
                {
                    if( $object->isDynamic() )
                    {
                        $lines .= $encloseFunction('group-dynamic');
                        $lines .= $encloseFunction('');
                    }
                    else
                    {
                        $lines .= $encloseFunction('group-static');
                        $lines .= $encloseFunction($object->members());
                    }
                }
                elseif ( $object->isAddress() )
                {
                    if( $object->isTmpAddr() )
                        $lines .= $encloseFunction('unknown');
                    else
                    {
                        $lines .= $encloseFunction($object->type());
                        $lines .= $encloseFunction($object->value());
                        $lines .= $encloseFunction($object->description(), false);
                    }
                }

                $lines .= "</tr>\n";

            }
        }

        $content = file_get_contents(dirname(__FILE__).'/common/html-export-template.html');
        $content = str_replace('%TableHeaders%',
                                '<th>location</th><th>name</th><th>type</th><th>value</th><th>description</th>',
                                $content);

        $content = str_replace('%lines%', $lines, $content);

        $jscontent =  file_get_contents(dirname(__FILE__).'/common/jquery-1.11.js');
        $jscontent .= "\n";
        $jscontent .= file_get_contents(dirname(__FILE__).'/common/jquery.stickytableheaders.min.js');
        $jscontent .= "\n\$('table').stickyTableHeaders();\n";

        $content = str_replace('%JSCONTENT%', $jscontent, $content);

        file_put_contents($filename, $content);


        file_put_contents($filename, $content);
    },
    'args' => Array(    'filename' => Array( 'type' => 'string', 'default' => '*nodefault*'  ) )
);


$supportedActions['replacebymembersanddelete'] = Array(
    'name' => 'replaceByMembersAndDelete',
    'MainFunction' => function ( AddressCallContext $context )
    {
        $object = $context->object;

        if( !$object->isGroup() )
        {
            echo $context->padding." - SKIPPED : it's not a group\n";
            return;
        }

        if( $object->owner === null )
        {
            echo $context->padding." -  SKIPPED : object was previously removed\n";
            return;
        }

        $objectRefs = $object->getReferences();
        $clearForAction = true;
        foreach( $objectRefs as $objectRef )
        {
            $class = get_class($objectRef);
            if( $class != 'AddressRuleContainer' && $class != 'AddressGroup' )
            {
                $clearForAction = false;
                echo "- SKIPPED : it's used in unsupported class $class\n";
                return;
            }
        }
        if( $clearForAction )
        {
            foreach( $objectRefs as $objectRef )
            {
                $class = get_class($objectRef);
                /** @var AddressRuleContainer|AddressGroup $objectRef */

                if( $objectRef->owner === null )
                {
                    echo $context->padding."  - SKIPPED because object already removed ({$objectRef->toString()})\n";
                    continue;
                }

                echo $context->padding."  - adding members in {$objectRef->toString()}\n";

                if( $class == 'AddressRuleContainer' )
                {
                    /** @var AddressRuleContainer $objectRef */
                    foreach( $object->members() as $objectMember )
                    {
                        if( $context->isAPI )
                            $objectRef->API_add($objectMember);
                        else
                            $objectRef->addObject($objectMember);

                        echo $context->padding."     -> {$objectMember->toString()}\n";
                    }
                    if( $context->isAPI )
                        $objectRef->API_remove($object);
                    else
                        $objectRef->remove($object);
                }
                elseif( $class == 'AddressGroup')
                {
                    /** @var AddressGroup $objectRef */
                    foreach( $object->members() as $objectMember )
                    {
                        if( $context->isAPI )
                            $objectRef->API_addMember($objectMember);
                        else
                            $objectRef->addMember($objectMember);
                        echo $context->padding."     -> {$objectMember->toString()}\n";
                    }
                    if( $context->isAPI )
                        $objectRef->API_removeMember($object);
                    else
                        $objectRef->removeMember($object);
                }
                else
                {
                    derr('unsupported class');
                }

            }

            if( $context->isAPI )
                $object->owner->API_remove($object, true);
            else
                $object->owner->remove($object, true);
        }
    },
);

$supportedActions['name-addprefix'] = Array(
    'name' => 'name-addPrefix',
    'MainFunction' =>  function ( AddressCallContext $context )
    {
        $object = $context->object;
        $newName = $context->arguments['prefix'].$object->name();
        echo $context->padding." - new name will be '{$newName}'\n";
        if( strlen($newName) > 63 )
        {
            echo $context->padding." *** SKIPPED : resulting name is too long\n";
            return;
        }
        $rootObject = PH::findRootObjectOrDie($object->owner->owner);

        if( $rootObject->isPanorama() && $object->owner->find($newName, null, false) !== null ||
            $rootObject->isFirewall() && $object->owner->find($newName, null, true) !== null   )
        {
            echo $context->padding." *** SKIPPED : an object with same name already exists\n";
            return;
        }
        if( $context->isAPI )
            $object->API_setName($newName);
        else
            $object->setName($newName);
    },
    'args' => Array( 'prefix' => Array( 'type' => 'string', 'default' => '*nodefault*' )
                    ),
);
$supportedActions['name-addsuffix'] = Array(
    'name' => 'name-addSuffix',
    'MainFunction' =>  function ( AddressCallContext $context )
    {
        $object = $context->object;
        $newName = $object->name().$context->arguments['suffix'];
        echo $context->padding." - new name will be '{$newName}'\n";
        if( strlen($newName) > 63 )
        {
            echo $context->padding." *** SKIPPED : resulting name is too long\n";
            return;
        }
        $rootObject = PH::findRootObjectOrDie($object->owner->owner);

        if( $rootObject->isPanorama() && $object->owner->find($newName, null, false) !== null ||
            $rootObject->isFirewall() && $object->owner->find($newName, null, true) !== null   )
        {
            echo $context->padding." *** SKIPPED : an object with same name already exists\n";
            return;
        }
        if( $context->isAPI )
            $object->API_setName($newName);
        else
            $object->setName($newName);
    },
    'args' => Array( 'suffix' => Array( 'type' => 'string', 'default' => '*nodefault*' )
    ),
);
$supportedActions['name-removeprefix'] = Array(
    'name' => 'name-removePrefix',
    'MainFunction' =>  function ( AddressCallContext $context )
    {
        $object = $context->object;
        $prefix = $context->arguments['prefix'];

        if( strpos($object->name(), $prefix) !== 0 )
        {
            echo $context->padding." *** SKIPPED : prefix not found\n";
            return;
        }
        $newName = substr($object->name(), strlen($prefix));

        if ( !preg_match("/^[a-zA-Z0-9]/", $newName[0]) )
        {
            echo $context->padding." *** SKIPPED : object name contains not allowed character at the beginning\n";
            return;
        }

        echo $context->padding." - new name will be '{$newName}'\n";

        $rootObject = PH::findRootObjectOrDie($object->owner->owner);

        if( $rootObject->isPanorama() && $object->owner->find($newName, null, false) !== null ||
            $rootObject->isFirewall() && $object->owner->find($newName, null, true) !== null   )
        {
            echo $context->padding." *** SKIPPED : an object with same name already exists\n";
            return;
        }
        if( $context->isAPI )
            $object->API_setName($newName);
        else
            $object->setName($newName);
    },
    'args' => Array( 'prefix' => Array( 'type' => 'string', 'default' => '*nodefault*' )
    ),
);
$supportedActions['name-removesuffix'] = Array(
    'name' => 'name-removeSuffix',
    'MainFunction' =>  function ( AddressCallContext $context )
    {
        $object = $context->object;
        $suffix = $context->arguments['suffix'];
        $suffixStartIndex = strlen($object->name()) - strlen($suffix);

        if( substr($object->name(), $suffixStartIndex, strlen($object->name()) ) != $suffix )
        {
            echo $context->padding." *** SKIPPED : suffix not found\n";
            return;
        }
        $newName = substr( $object->name(), 0, $suffixStartIndex );

        echo $context->padding." - new name will be '{$newName}'\n";

        $rootObject = PH::findRootObjectOrDie($object->owner->owner);

        if( $rootObject->isPanorama() && $object->owner->find($newName, null, false) !== null ||
            $rootObject->isFirewall() && $object->owner->find($newName, null, true) !== null   )
        {
            echo $context->padding." *** SKIPPED : an object with same name already exists\n";
            return;
        }
        if( $context->isAPI )
            $object->API_setName($newName);
        else
            $object->setName($newName);
    },
    'args' => Array( 'suffix' => Array( 'type' => 'string', 'default' => '*nodefault*' )
    ),
);

$supportedActions['move'] = Array(
    'name' => 'move',
    'MainFunction' =>  function ( AddressCallContext $context )
    {
        $object = $context->object;

        $localLocation = 'shared';

        if( ! $object->owner->owner->isPanorama() && !$object->owner->owner->isFirewall() )
            $localLocation = $object->owner->owner->name();

        $targetLocation = $context->arguments['location'];
        $targetStore = null;

        if( $localLocation == $targetLocation )
        {
            echo $context->padding." * SKIPPED because original and target destinations are the same: $targetLocation\n";
            return;
        }

        $rootObject = PH::findRootObjectOrDie($object->owner->owner);

        if( $targetLocation == 'shared' )
        {
            $targetStore = $rootObject->addressStore;
        }
        else
        {
            $findSubSystem = $rootObject->findSubSystemByName($targetLocation);
            if( $findSubSystem === null )
                derr("cannot find VSYS/DG named '$targetLocation'");

            $targetStore = $findSubSystem->addressStore;
        }

        if( $localLocation == 'shared' )
        {
            echo $context->padding."   * SKIPPED : moving from SHARED to sub-level is not yet supported\n";
            return;
        }

        if( $localLocation != 'shared' && $targetLocation != 'shared' )
        {
            if( $context->baseObject->isFirewall() )
            {
                echo $context->padding."   * SKIPPED : moving between VSYS is not supported\n";
                return;
            }

            echo $context->padding."   * SKIPPED : moving between 2 VSYS/DG is not supported yet\n";
            return;
        }

        $conflictObject = $targetStore->find($object->name() ,null, false);
        if( $conflictObject === null )
        {
            echo $context->padding."   * moved, no conflict\n";
            if( $context->isAPI )
            {
                $oldXpath = $object->getXPath();
                $object->owner->remove($object);
                $targetStore->add($object);
                $object->API_sync();
                $context->connector->sendDeleteRequest($oldXpath);
            }
            else
            {
                $object->owner->remove($object);
                $targetStore->add($object);
            }
            return;
        }

        if( $context->arguments['mode'] == 'skipifconflict' )
        {
            echo $context->padding."   * SKIPPED : there is an object with same name. Choose another mode to to resolve this conflict\n";
            return;
        }

        echo $context->padding."   - there is a conflict with type ";
        if( $conflictObject->isGroup() )
            echo "Group\n";
        else
            echo $conflictObject->type()."\n";

        if( $conflictObject->isGroup() && !$object->isGroup() || !$conflictObject->isGroup() && $object->isGroup() )
        {
            echo $context->padding."   * SKIPPED because conflict has mismatching types\n";
            return;
        }

        if( $conflictObject->isTmpAddr() )
        {
            derr("unsupported situation with a temporary object");
            return;
        }

        if( $object->isTmpAddr() )
        {
            echo $context->padding."   * SKIPPED because this object is Tmp\n";
            return;
        }

        if( $object->isGroup() )
        {
            if( $object->equals($conflictObject) )
            {
                echo "    * Removed because target has same content\n";

                $object->replaceMeGlobally($conflictObject);
                if($context->isAPI)
                    $object->owner->API_remove($object);
                else
                    $object->owner->remove($object);

                return;
            }
            else
            {
                $object->displayValueDiff($conflictObject, 9);
                if( $context->arguments['mode'] == 'removeifmatch')
                {
                    echo $context->padding."    * SKIPPED because of mismatching group content\n";
                    return;
                }

                $localMap = $object->getIP4Mapping();
                $targetMap = $conflictObject->getIP4Mapping();

                if( !$localMap->equals($targetMap) )
                {
                    echo $context->padding."    * SKIPPED because of mismatching group content and numerical values\n";
                    return;
                }

                echo $context->padding."    * Removed because it has same numerical value\n";

                $object->replaceMeGlobally($conflictObject);
                if($context->isAPI)
                    $object->owner->API_remove($object);
                else
                    $object->owner->remove($object);

                return;

            }
        }

        if( $object->equals($conflictObject) )
        {
            echo "    * Removed because target has same content\n";
            $object->replaceMeGlobally($conflictObject);

            if($context->isAPI)
                $object->owner->API_remove($object);
            else
                $object->owner->remove($object);
            return;
        }

        if( $context->arguments['mode'] == 'removeifmatch' )
            return;

        $localMap = $object->getIP4Mapping();
        $targetMap = $conflictObject->getIP4Mapping();

        if( !$localMap->equals($targetMap) )
        {
            echo $context->padding."    * SKIPPED because of mismatching content and numerical values\n";
            return;
        }

        echo "    * Removed because target has same numerical value\n";

        $object->replaceMeGlobally($conflictObject);
        if($context->isAPI)
            $object->owner->API_remove($object);
        else
            $object->owner->remove($object);


    },
    'args' => Array( 'location' => Array( 'type' => 'string', 'default' => '*nodefault*' ),
                     'mode' => Array( 'type' => 'string', 'default' => 'skipIfConflict', 'choices' => Array( 'skipIfConflict', 'removeIfMatch', 'removeIfNumericalMatch') )
    ),
);


$supportedActions['showip4mapping'] = Array(
    'name' => 'showIP4Mapping',
    'MainFunction' => function ( AddressCallContext $context )
                {
                    $object = $context->object;

                    if( $object->isGroup() )
                    {
                        $resolvMap=$object->getIP4Mapping();
                        echo $context->padding."* {$resolvMap->count()} entries\n";
                        foreach($resolvMap->getMapArray() as &$resolvRecord)
                        {
                            echo $context->padding." - ".str_pad(long2ip($resolvRecord['start']), 14)." - ".long2ip($resolvRecord['end'])."\n";
                        }
                        /*foreach($resolvMap['unresolved'] as &$resolvRecord)
                        {
                            echo "     * UNRESOLVED: {$resolvRecord->name()}\n";
                        }*/

                    }
                    else
                    {
                        $type = $object->type();

                        if( $type == 'ip-netmask' || $type == 'ip-range' )
                        {
                            $resolvMap = $object->getIP4Mapping()->getMapArray();
                            $resolvMap = reset($resolvMap);
                            echo $context->padding." - ".str_pad(long2ip($resolvMap['start']), 14)." - ".long2ip($resolvMap['end'])."\n";
                        }
                        else echo $context->padding." - UNSUPPORTED \n";
                    }
                }
);


$supportedActions['displayreferences'] = Array(
    'name' => 'displayReferences',
    'MainFunction' =>  function ( AddressCallContext $context )
    {
        $object = $context->object;
        $object->display_references(7);
    },
);


$supportedActions['display'] = Array(
    'name' => 'display',
    'MainFunction' =>  function ( AddressCallContext $context )
    {
        $object = $context->object;

        if( $object->isGroup() )
        {
            if( $object->isDynamic() )
            {
                echo $context->padding."* " . get_class($object) . " '{$object->name()}' (DYNAMIC)\n";
            }
            else
            {
                echo $context->padding."* " . get_class($object) . " '{$object->name()}' ({$object->count()} members)\n";

                foreach ($object->members() as $member)
                    echo "          - {$member->name()}\n";
            }
        }
        else
            echo $context->padding."* ".get_class($object)." '{$object->name()}'  value: '{$object->value()}'\n";

        echo "\n";
    },
);
// </editor-fold>


PH::processCliArgs();

$nestedQueries = Array();

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

if( isset(PH::$args['loadplugin']) )
{
    $pluginFile = PH::$args['loadplugin'];
    echo " * loadPlugin was used. Now loading file: '{$pluginFile}'...";
    require_once $pluginFile;
    echo "OK!\n";
}


if( isset(PH::$args['listactions']) )
{
    ksort($supportedActions);

    echo "Listing of supported actions:\n\n";

    echo str_pad('', 100, '-')."\n";
    echo str_pad('Action name', 28, ' ', STR_PAD_BOTH)."|".str_pad("Argument:Type",24, ' ', STR_PAD_BOTH)." |".
        str_pad("Def. Values",12, ' ', STR_PAD_BOTH)."|   Choices\n";
    echo str_pad('', 100, '-')."\n";

    foreach($supportedActions as &$action )
    {

        $output = "* ".$action['name'];

        $output = str_pad($output, 28).'|';

        if( isset($action['args']) )
        {
            $first = true;
            $count=1;
            foreach($action['args'] as $argName => &$arg)
            {
                if( !$first )
                    $output .= "\n".str_pad('',28).'|';

                $output .= " ".str_pad("#$count $argName:{$arg['type']}", 24)."| ".str_pad("{$arg['default']}",12)."| ";
                if( isset($arg['choices']) )
                {
                    $output .= PH::list_to_string($arg['choices']);
                }

                $count++;
                $first = false;
            }
        }


        echo $output."\n";

        echo str_pad('', 100, '=')."\n";

        //echo "\n";
    }

    exit(0);
}

if( isset(PH::$args['listfilters']) )
{
    ksort(RQuery::$defaultFilters['address']);

    echo "Listing of supported filters:\n\n";

    foreach(RQuery::$defaultFilters['address'] as $index => &$filter )
    {
        echo "* ".$index."\n";
        ksort( $filter['operators'] );

        foreach( $filter['operators'] as $oindex => &$operator)
        {
            //if( $operator['arg'] )
            $output = "    - $oindex";

            echo $output."\n";
        }
        echo "\n";
    }

    exit(0);
}



if( ! isset(PH::$args['in']) )
    display_error_usage_exit('"in" is missing from arguments');
$configInput = PH::$args['in'];
if( !is_string($configInput) || strlen($configInput) < 1 )
    display_error_usage_exit('"in" argument is not a valid string');



if( ! isset(PH::$args['actions']) )
    display_error_usage_exit('"actions" is missing from arguments');
$doActions = PH::$args['actions'];
if( !is_string($doActions) || strlen($doActions) < 1 )
    display_error_usage_exit('"actions" argument is not a valid string');


if( isset(PH::$args['dryrun'])  )
{
    $dryRun = PH::$args['dryrun'];
    if( $dryRun === 'yes' ) $dryRun = true;
    if( $dryRun !== true || $dryRun !== false )
        display_error_usage_exit('"dryrun" argument has an invalid value');
}

if( isset(PH::$args['debugapi'])  )
{
    $debugAPI = true;
}



//
// What kind of config input do we have.
//     File or API ?
//
// <editor-fold desc="  ****  input method validation and PANOS vs Panorama auto-detect  ****" defaultstate="collapsed" >
$configInput = PH::processIOMethod($configInput, true);
$xmlDoc = null;

if( $configInput['status'] == 'fail' )
{
    fwrite(STDERR, "\n\n**ERROR** " . $configInput['msg'] . "\n\n");exit(1);
}

if( $configInput['type'] == 'file' )
{
    if(isset(PH::$args['out']) )
    {
        $configOutput = PH::$args['out'];
        if (!is_string($configOutput) || strlen($configOutput) < 1)
            display_error_usage_exit('"out" argument is not a valid string');
    }
    else
        display_error_usage_exit('"out" is missing from arguments');

    if( !file_exists($configInput['filename']) )
        derr("file '{$configInput['filename']}' not found");

    $xmlDoc = new DOMDocument();
    if( ! $xmlDoc->load($configInput['filename']) )
        derr("error while reading xml config file");

}
elseif ( $configInput['type'] == 'api'  )
{
    if($debugAPI)
        $configInput['connector']->setShowApiCalls(true);
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
    $pan = new PANConf();
else
    $pan = new PanoramaConf();

echo " - Detected platform type is '{$configType}'\n";

if( $configInput['type'] == 'api' )
    $pan->connector = $configInput['connector'];
// </editor-fold>



//
// Rule filter provided in CLI ?
//
if( isset(PH::$args['filter'])  )
{
    $objectsFilter = PH::$args['filter'];
    if( !is_string($objectsFilter) || strlen($objectsFilter) < 1 )
        display_error_usage_exit('"filter" argument is not a valid string');
}


//
// Config is PANOS or Panorama ?
//
$configType = strtolower($configType);
if( $configType != 'panos' && $configType != 'panorama' )
    display_error_usage_exit('"type" has unsupported value: '.$configType);

//
// Location provided in CLI ?
//
if( isset(PH::$args['location'])  )
{
    $objectsLocation = PH::$args['location'];
    if( !is_string($objectsLocation) || strlen($objectsLocation) < 1 )
        display_error_usage_exit('"location" argument is not a valid string');
}
else
{
    if( $configType == 'panos' )
    {
        echo " - No 'location' provided so using default ='vsys1'\n";
        $objectsLocation = 'vsys1';
    }
    else
    {
        echo " - No 'location' provided so using default ='shared'\n";
        $objectsLocation = 'shared';
    }
}


//
// Extracting actions
//
$explodedActions = explode('/', $doActions);
/** @var AddressCallContext[] $doActions */
$doActions = Array();
foreach( $explodedActions as &$exAction )
{
    $explodedAction = explode(':', $exAction);
    if( count($explodedAction) > 2 )
        display_error_usage_exit('"actions" argument has illegal syntax: '.PH::$args['actions']);

    $actionName = strtolower($explodedAction[0]);

    if( !isset($supportedActions[$actionName]) )
    {
        display_error_usage_exit('unsupported Action: "'.$actionName.'"');
    }

    if( count($explodedAction) == 1 )
        $explodedAction[1] = '';

    $context = new AddressCallContext($supportedActions[$actionName], $explodedAction[1]);
    $context->baseObject = $pan;
    if( $configInput['type'] == 'api' )
    {
        $context->isAPI = true;
        $context->connector = $pan->connector;
    }

    $doActions[] = $context;
}
//
// ---------


//
// create a RQuery if a filter was provided
//
/**
 * @var RQuery $objectFilterRQuery
 */
$objectFilterRQuery = null;
if( $objectsFilter !== null )
{
    $objectFilterRQuery = new RQuery('address');
    $res = $objectFilterRQuery->parseFromString($objectsFilter, $errorMessage);
    if( $res === false )
    {
        fwrite(STDERR, "\n\n**ERROR** Rule filter parser: " . $errorMessage . "\n\n");
        exit(1);
    }

    echo " - filter after sanitization : ".$objectFilterRQuery->sanitizedString()."\n";
}
// --------------------


//
// load the config
//
echo " - Loading configuration through PAN-Configurator library... ";
$loadStartMem = memory_get_usage(true);
$loadStartTime = microtime(true);
$pan->load_from_domxml($xmlDoc);
$loadEndTime = microtime(true);
$loadEndMem = memory_get_usage(true);
$loadElapsedTime = number_format( ($loadEndTime - $loadStartTime), 2, '.', '');
$loadUsedMem = convert($loadEndMem - $loadStartMem);
echo "OK! ($loadElapsedTime seconds, $loadUsedMem memory)\n";
// --------------------


//
// Location Filter Processing
//

// <editor-fold desc=" ****  Location Filter Processing  ****" defaultstate="collapsed" >
/**
 * @var RuleStore[] $ruleStoresToProcess
 */
$objectsLocation = explode(',', $objectsLocation);

foreach( $objectsLocation as &$location )
{
    if( strtolower($location) == 'shared' )
        $location = 'shared';
    else if( strtolower($location) == 'any' )
        $location = 'any';
    else if( strtolower($location) == 'all' )
        $location = 'any';
}
unset($location);

$objectsLocation = array_unique($objectsLocation);
$objectsToProcess = Array();

foreach( $objectsLocation as $location )
{
    $locationFound = false;

    if( $configType == 'panos')
    {
        if( $location == 'shared' || $location == 'any'  )
        {
            $objectsToProcess[] = Array('store' => $pan->addressStore, 'objects' => $pan->addressStore->all());
            $locationFound = true;
        }
        foreach ($pan->getVirtualSystems() as $sub)
        {
            if( ($location == 'any' || $location == 'all' || $location == $sub->name() && !isset($ruleStoresToProcess[$sub->name()]) ))
            {
                $objectsToProcess[] = Array('store' => $sub->addressStore, 'objects' => $sub->addressStore->all());
                $locationFound = true;
            }
        }
    }
    else
    {
        if( $location == 'shared' || $location == 'any' )
        {

            $objectsToProcess[] = Array('store' => $pan->addressStore, 'objects' => $pan->addressStore->all());
            $locationFound = true;
        }

        foreach( $pan->getDeviceGroups() as $sub )
        {
            if( ($location == 'any' || $location == 'all' || $location == $sub->name()) && !isset($ruleStoresToProcess[$sub->name().'%pre']) )
            {
                $objectsToProcess[] = Array('store' => $sub->addressStore, 'objects' => $sub->addressStore->all() );
                $locationFound = true;
            }
        }
    }

    if( !$locationFound )
    {
        echo "ERROR: location '$location' was not found. Here is a list of available ones:\n";
        echo " - shared\n";
        if( $configType == 'panos' )
        {
            foreach( $pan->getVirtualSystems() as $sub )
            {
                echo " - ".$sub->name()."\n";
            }
        }
        else
        {
            foreach( $pan->getDeviceGroups() as $sub )
            {
                echo " - ".$sub->name()."\n";
            }
        }
        echo "\n\n";
        exit(1);
    }
}
// </editor-fold>


//
// It's time to process Rules !!!!
//

// <editor-fold desc=" *****  Object Processing  *****" defaultstate="collapsed" >

$totalObjectsProcessed = 0;

foreach( $objectsToProcess as &$objectsRecord )
{
    $subObjectsProcessed = 0;

    $store = $objectsRecord['store'];
    $objects = &$objectsRecord['objects'];
    foreach( $doActions as $doAction )
    {
        $doAction->subSystem = $store->owner;
    }

    echo "\n* processing store '".PH::boldText($store->toString())." that holds ".count($objects)." objects\n";


    foreach($objects as $object )
    {
        /** @var Address|AddressGroup $object */
        if( $objectFilterRQuery !== null )
        {
            $queryResult = $objectFilterRQuery->matchSingleObject(Array('object' =>$object, 'nestedQueries'=>&$nestedQueries));
            if( !$queryResult )
                continue;
        }

        $totalObjectsProcessed++;
        $subObjectsProcessed++;

        //mwarning($object->name());

        foreach( $doActions as $doAction )
        {
            $doAction->padding = '     ';
            $doAction->executeAction($object);
            echo "\n";
        }
    }

    echo "\n* objects processed in DG/Vsys '{$store->owner->name()}' : $subObjectsProcessed\n\n";
}
// </editor-fold>


$first  = true;
foreach( $doActions as $doAction )
{
    if( $doAction->hasGlobalFinishAction() )
    {
        $first = false;
        $doAction->executeGlobalFinishAction();
    }
}


echo "\n **** PROCESSING OF $totalObjectsProcessed OBJECTS DONE **** \n\n";

if( isset(PH::$args['stats']) )
{
    $pan->display_statistics();
    echo "\n";
    $processedLocations = Array();
    foreach( $objectsToProcess as &$record )
    {
        if( get_class($record['store']->owner) != 'PanoramaConf' && get_class($record['store']->owner) != 'PANConf' )
        {
            /** @var DeviceGroup|VirtualSystem $sub */
            $sub = $record['store']->owner;
            if( isset($processedLocations[$sub->name()]) )
                continue;

            $processedLocations[$sub->name()] = true;
            $sub->display_statistics();
            echo "\n";
        }
    }
}


// save our work !!!
if( $configOutput !== null )
{
    if( $configOutput != '/dev/null' )
    {
        $pan->save_to_file($configOutput);
    }
}

echo "\n\n********** END OF ADDRESS-EDIT UTILITY ***********\n";
echo     "**************************************************\n";
echo "\n\n";



