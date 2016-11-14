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

RuleCallContext::$commonActionFunctions['calculate-addresses'] = Array(
    'function' => function (RuleCallContext $context, $srcOrDST)
    {
        $rule = $context->object;

        $zoneContainer = null;
        $addressContainer = null;

        if( $srcOrDST == 'src' )
        {
            $zoneContainer = $rule->from;
            $addressContainer = $rule->source;
        }
        elseif( $srcOrDST == 'dst' )
        {
            $zoneContainer = $rule->to;
            $addressContainer = $rule->destination;
        }
        else
            derr('unsupported');

        $mode = $context->arguments['mode'];
        $system = $rule->owner->owner;

        /** @var VirtualRouter $virtualRouterToProcess */
        $virtualRouterToProcess = null;

        if( !isset($context->cachedIPmapping) )
            $context->cachedIPmapping = Array();

        $serial = spl_object_hash($rule->owner);
        $configIsOnLocalFirewall = FALSE;

        if( !isset($context->cachedIPmapping[$serial]) )
        {
            if( $system->isDeviceGroup() || $system->isPanorama() )
            {
                $firewall = null;
                $panorama = $system;
                if( $system->isDeviceGroup() )
                    $panorama = $system->owner;

                if( $context->arguments['template'] == $context->actionRef['args']['template']['default'] )
                    derr('with Panorama configs, you need to specify a template name');

                if( $context->arguments['virtualRouter'] == $context->actionRef['args']['virtualRouter']['default'] )
                    derr('with Panorama configs, you need to specify virtualRouter argument. Available virtual routes are: ');

                $_tmp_explTemplateName = explode('@', $context->arguments['template']);
                if( count($_tmp_explTemplateName) > 1 )
                {
                    $firewall = new PANConf();
                    $configIsOnLocalFirewall = TRUE;
                    $doc = null;

                    if( strtolower($_tmp_explTemplateName[0]) == 'api' )
                    {
                        $panoramaConnector = findConnector($system);
                        $connector = new PanAPIConnector($panoramaConnector->apihost, $panoramaConnector->apikey, 'panos-via-panorama', $_tmp_explTemplateName[1]);
                        $firewall->connector = $connector;
                        $doc = $connector->getMergedConfig();
                        $firewall->load_from_domxml($doc);
                        unset($connector);
                    }
                    elseif( strtolower($_tmp_explTemplateName[0]) == 'file' )
                    {
                        $filename = $_tmp_explTemplateName[1];
                        if( !file_exists($filename) )
                            derr("cannot read firewall configuration file '{$filename}''");
                        $doc = new DOMDocument();
                        if( !$doc->load($filename) )
                            derr("invalive xml file" . libxml_get_last_error()->message);
                        unset($filename);
                    }
                    else
                        derr("unsupported method: {$_tmp_explTemplateName[0]}@");


                    // delete rules to avoid loading all the config
                    $deletedNodesCount = DH::removeChildrenElementsMatchingXPath("/config/devices/entry/vsys/entry/rulebase/*", $doc);
                    if( $deletedNodesCount === FALSE )
                        derr("xpath issue");
                    $deletedNodesCount = DH::removeChildrenElementsMatchingXPath("/config/shared/rulebase/*", $doc);
                    if( $deletedNodesCount === FALSE )
                        derr("xpath issue");

                    //print "\n\n deleted $deletedNodesCount nodes \n\n";

                    $firewall->load_from_domxml($doc);

                    unset($deletedNodesCount);
                    unset($doc);
                }


                /** @var Template $template */
                if( !$configIsOnLocalFirewall )
                {
                    $template = $panorama->findTemplate($context->arguments['template']);
                    if( $template === null )
                        derr("cannot find Template named '{$context->arguments['template']}'. Available template list:" . PH::list_to_string($panorama->templates));
                }

                if( $configIsOnLocalFirewall )
                    $virtualRouterToProcess = $firewall->network->virtualRouterStore->findVirtualRouter($context->arguments['virtualRouter']);
                else
                    $virtualRouterToProcess = $template->deviceConfiguration->network->virtualRouterStore->findVirtualRouter($context->arguments['virtualRouter']);

                if( $virtualRouterToProcess === null )
                {
                    if( $configIsOnLocalFirewall )
                        $tmpVar = $firewall->network->virtualRouterStore->virtualRouters();
                    else
                        $tmpVar = $template->deviceConfiguration->network->virtualRouterStore->virtualRouters();

                    derr("cannot find VirtualRouter named '{$context->arguments['virtualRouter']}' in Template '{$context->arguments['template']}'. Available VR list: " . PH::list_to_string($tmpVar));
                }

                if( (!$configIsOnLocalFirewall && count($template->deviceConfiguration->virtualSystems) == 1) || ($configIsOnLocalFirewall && count($firewall->virtualSystems) == 1) )
                {
                    if( $configIsOnLocalFirewall )
                        $system = $firewall->virtualSystems[0];
                    else
                        $system = $template->deviceConfiguration->virtualSystems[0];
                }
                else
                {
                    $vsysConcernedByVR = $virtualRouterToProcess->findConcernedVsys();
                    if( count($vsysConcernedByVR) == 1 )
                    {
                        $system = array_pop($vsysConcernedByVR);
                    }
                    elseif( $context->arguments['vsys'] == '*autodetermine*' )
                    {
                        derr("cannot autodetermine resolution context from Template '{$context->arguments['template']}' VR '{$context->arguments['virtualRouter']}'' , multiple VSYS are available: " . PH::list_to_string($vsysConcernedByVR) . ". Please provide choose a VSYS.");
                    }
                    else
                    {
                        if( $configIsOnLocalFirewall )
                            $vsys = $firewall->findVirtualSystem($context->arguments['vsys']);
                        else
                            $vsys = $template->deviceConfiguration->findVirtualSystem($context->arguments['vsys']);
                        if( $vsys === null )
                            derr("cannot find VSYS '{$context->arguments['vsys']}' in Template '{$context->arguments['template']}'");
                        $system = $vsys;
                    }
                }

                //derr(DH::dom_to_xml($template->deviceConfiguration->xmlroot));
                //$tmpVar = $system->importedInterfaces->interfaces();
                //derr(count($tmpVar)." ".PH::list_to_string($tmpVar));
            }
            else if( $context->arguments['virtualRouter'] != '*autodetermine*' )
            {
                $virtualRouterToProcess = $system->owner->network->virtualRouterStore->findVirtualRouter($context->arguments['virtualRouter']);
                if( $virtualRouterToProcess === null )
                    derr("VirtualRouter named '{$context->arguments['virtualRouter']}' not found");
            }
            else
            {
                $vRouters = $system->owner->network->virtualRouterStore->virtualRouters();
                $foundRouters = Array();

                foreach( $vRouters as $router )
                {
                    foreach( $router->attachedInterfaces->interfaces() as $if )
                    {
                        if( $system->importedInterfaces->hasInterfaceNamed($if->name()) )
                        {
                            $foundRouters[] = $router;
                            break;
                        }
                    }
                }

                print $context->padding . " - VSYS/DG '{$system->name()}' has interfaces attached to " . count($foundRouters) . " virtual routers\n";
                if( count($foundRouters) > 1 )
                    derr("more than 1 suitable virtual routers found, please specify one fo the following: " . PH::list_to_string($foundRouters));
                if( count($foundRouters) == 0 )
                    derr("no suitable VirtualRouter found, please force one or check your configuration");

                $virtualRouterToProcess = $foundRouters[0];
            }

            $context->cachedIPmapping[$serial] = $virtualRouterToProcess->getIPtoZoneRouteMapping($system);
        }

        $ipMapping = &$context->cachedIPmapping[$serial];

        if( $addressContainer->isAny() )
        {
            if( $zoneContainer->isAny() )
            {
                print $context->padding . " - SKIPPED : zone container is ANY()\n";
                return;
            }

        }
        else
        {
            print $context->padding . " - SKIPPED : address container is not ANY()\n";
            return;
        }

        foreach( $zoneContainer->zones() as $zone )
        {
            $zonename = $zone->name();
            print $context->padding . " - now calculate IP4Mapping based on the zone: " . $zonename . "\n";

            $resolvedAddresses = &$addressContainer->calculateIP4MappingFromZones($ipMapping['ipv4'], $zonename);

            if( $resolvedAddresses->count() == 0 )
            {
                print $context->padding . " - WARNING : no addresses resolved (Zone? Route?)\n";
                return;
            }

            $mapArray = $resolvedAddresses->getMapArray();

            $prefix = 'TMP_';

            $addrgroup = $addressContainer->parentCentralStore->find($prefix . $zonename);
            if( $addrgroup === null )
                $addrgroup = $addressContainer->parentCentralStore->newAddressGroup($prefix . $zonename);


            foreach( $mapArray as $addressobject )
            {
                $objectname = long2ip($addressobject['start']) . "-" . long2ip($addressobject['end']);

                $object = $addressContainer->parentCentralStore->find($prefix . $objectname);
                if( $object === null )
                    $object = $addressContainer->parentCentralStore->newAddress($prefix . $objectname, 'ip-range', $objectname);

                $addrgroup->addMember($object);
            }

            $addressContainer->addObject($addrgroup);
        }
    },
    'args' => Array(    'mode' => Array(    'type' => 'string',
        'default' => 'show',
        'choices' => Array('replace', 'show'),
        'help' =>   "Will determine what to do with resolved addresses : show them, replace SRC is.any in the rule"
    ),
        'virtualRouter' => Array(   'type' => 'string',
            'default' => '*autodetermine*',
            'help' =>   "Can optionally be provided if script cannot find which virtualRouter it should be using".
                " (ie: there are several VR in same VSYS)"
        ),
        'template' => Array(    'type' => 'string',
            'default' => '*notPanorama*',
            'help' =>   "When you are using Panorama then 1 or more templates could apply to a DeviceGroup, in".
                " such a case you may want to specify which Template name to use.\nBeware that if the Template is overriden".
                " or if you are not using Templates then you will want load firewall config in lieu of specifying a template.".
                " \nFor this, give value 'api@XXXXX' where XXXXX is serial number of the Firewall device number you want to use to".
                " calculate zones.\nIf you don't want to use API but have firewall config file on your computer you can then".
                " specify file@/folderXYZ/config.xml."
        ),
        'vsys' => Array(    'type' => 'string',
            'default' => '*autodetermine*',
            'help' =>   "specify vsys when script cannot autodetermine it or when you when to manually override"
        ),
    ),
    'help' =>   "This Action will use routing tables to resolve zones. When the program cannot find all parameters by".
        " itself (like vsys or template name you will have ti manually provide them.\n\n".
        "Usage examples:\n\n".
        "    - xxx-calculate-zones\n".
        "    - xxx-calculate-zones:replace\n".
        "    - xxx-calculate-zones:show,vr1\n".
        "    - xxx-calculate-zones:replace,vr3,api@0011C890C,vsys1\n".
        "    - xxx-calculate-zones:show,vr5,Datacenter_template\n".
        "    - xxx-calculate-zones:replace,vr3,file@firewall.xml,vsys1\n"
);

RuleCallContext::$commonActionFunctions['zone-add'] = Array(
    'function' => function (RuleCallContext $context, $fromOrTo, $force)
    {
        $rule = $context->object;

        $zoneContainer = null;

        if( $fromOrTo == 'from' )
        {
            $zoneContainer  = $rule->from;
        }
        elseif( $fromOrTo == 'to' )
        {
            $zoneContainer  = $rule->to;
        }
        else
            derr('unsupported');

        $objectFind = $zoneContainer->parentCentralStore->find($context->arguments['zoneName']);
        if ($objectFind === null && $force == false)
            derr("zone named '{$context->arguments['zoneName']}' not found");

        $objectFind = $zoneContainer->parentCentralStore->findOrCreate($context->arguments['zoneName']);
        if ($objectFind === null)
            derr("zone named '{$context->arguments['zoneName']}' not found");

        if ($context->isAPI)
            $zoneContainer->API_addZone($objectFind);
        else
            $zoneContainer->addZone($objectFind);

    },
    'args' => Array( 'zoneName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);

RuleCallContext::$commonActionFunctions['zone-replace'] = Array(
    'function' => function (RuleCallContext $context, $fromOrTo )
    {
        $rule = $context->object;

        $zoneNameToReplace = $context->arguments['zoneToReplaceName'];
        $zoneNameForReplacement = $context->arguments['zoneForReplacementName'];
        $force = $context->arguments['force'];

        /** @var ZoneRuleContainer $zoneContainer */
        $zoneContainer = null;

        if( $fromOrTo == 'from' )
        {
            if( $rule->isPbfRule()  && $rule->isInterfaceBased()
                || $rule->isDoSRule() && $rule->isZoneBasedFrom() )
            {
                echo $context->padding." * SKIPPED : TO is Interface based.\n";
                return;
            }
            $zoneContainer  = $rule->from;
        }
        elseif( $fromOrTo == 'to' )
        {
            if( $rule->isPbfRule() )
            {
                echo $context->padding." * SKIPPED : there is no TO in PBF rules.\n";
                return;
            }
            if( $rule->isDoSRule() && $rule->isZoneBasedTo() )
            {
                echo $context->padding." * SKIPPED : TO is Interface based.\n";
                return;
            }
            $zoneContainer  = $rule->to;
        }
        else
            derr('unsupported');

        
        $zoneToReplace = $zoneContainer->parentCentralStore->find($zoneNameToReplace);
        if( $zoneToReplace === null )
            derr("zone '{$zoneNameToReplace}' does not exist. If it's intended then please use a REGEXP instead\n");

        if( !$zoneContainer->hasZone($zoneToReplace) )
        {
            print $context->padding." * SKIPPED : no zone with that name in the container\n";
            return;
        }

        $zoneForReplacement = $zoneContainer->parentCentralStore->find($zoneNameForReplacement);
        if( $zoneForReplacement === null )
        {
            if( !$force )
                derr("zone '{$zoneNameForReplacement}' does not exist. If it's intended then please use option force=TRUE to bypass this safeguard\n");
            $zoneForReplacement = $zoneContainer->parentCentralStore->createTmp($zoneNameForReplacement);
        }

        if ($context->isAPI)
        {
            $zoneContainer->API_addZone($zoneForReplacement);
            $zoneContainer->API_removeZone($zoneToReplace);
        }
        else
        {
            $zoneContainer->addZone($zoneForReplacement);
            $zoneContainer->removeZone($zoneToReplace);
        }

    },
    'args' => Array(    'zoneToReplaceName' => Array( 'type' => 'string', 'default' => '*nodefault*' ),
                        'zoneForReplacementName' => Array( 'type' => 'string', 'default' => '*nodefault*' ),
                        'force' => Array( 'type' => 'boolean', 'default' => 'no')
    )
);

/***************************************
 *
 *         Supported Actions
 *
 **************************************/
$supportedActions = Array();



// <editor-fold desc="Supported Actions Array" defaultstate="collapsed" >

//                                              //
//                Zone Based Actions            //
//                                              //
RuleCallContext::$supportedActions[] = Array(
    'name' => 'from-Add',
    'section' => 'zone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        if( ($rule->isPbfRule() && $rule->isZoneBased()) || ($rule->isDoSRule() && $rule->isZoneBasedFrom()) )
        {
            echo $context->padding." * SKIPPED: FROM is Zone based, not supported yet.\n";
            return;
        }


        $f = RuleCallContext::$commonActionFunctions['zone-add']['function'];
        $f($context, 'from', false);
    },
    'args' => & RuleCallContext::$commonActionFunctions['zone-add']['args'],
    'help' =>   "Adds a zone in the 'FROM' field of a rule. If FROM was set to ANY then it will be replaced by zone in argument.".
                "Zone must be existing already or script will out an error. Use action from-add-force if you want to add a zone that does not not exist."
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'from-Add-Force',
    'section' => 'zone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        if( ($rule->isPbfRule() && $rule->isZoneBased()) || ($rule->isDoSRule() && $rule->isZoneBasedFrom()) )
        {
            echo $context->padding." * SKIPPED: FROM is Zone based, not supported yet.\n";
            return;
        }
        $f = RuleCallContext::$commonActionFunctions['zone-add']['function'];
        $f($context, 'from', true);
    },
    'args' => &RuleCallContext::$commonActionFunctions['zone-add']['args'],
    'help' =>   "Adds a zone in the 'FROM' field of a rule. If FROM was set to ANY then it will be replaced by zone in argument."
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'from-Remove',
    'section' => 'zone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ($rule->isPbfRule() && $rule->isZoneBased()) || ($rule->isDoSRule() && $rule->isZoneBasedFrom()) )
        {
            echo $context->padding." * SKIPPED: FROM is Zone based, not supported yet.\n";
            return;
        }
        if( !$rule->from->hasZone($context->arguments['zoneName']) )
        {
            echo $context->padding." * SKIPPED : no zone with requested name was found";
            return;
        }

        $objectFind = $rule->from->parentCentralStore->find($context->arguments['zoneName']);
        if ($objectFind === null)
            derr("zone named '{$context->arguments['zoneName']}' not found");

        if ($context->isAPI)
            $rule->from->API_removeZone($objectFind);
        else
            $rule->from->removeZone($objectFind);
    },
    'args' => Array( 'zoneName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'from-Remove-Force-Any',
    'section' => 'zone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ($rule->isPbfRule() && $rule->isZoneBased()) || ($rule->isDoSRule() && $rule->isZoneBasedFrom()) )
        {
            echo $context->padding." * SKIPPED: FROM is Zone based, not supported yet.\n";
            return;
        }
        if( !$rule->from->hasZone($context->arguments['zoneName']) )
        {
            echo $context->padding." * SKIPPED : no zone with requested name was found";
            return;
        }

        $objectFind = $rule->from->parentCentralStore->find($context->arguments['zoneName']);
        if ($objectFind === null)
            derr("zone named '{$context->arguments['zoneName']}' not found");

        if ($context->isAPI)
            $rule->from->API_removeZone($objectFind, true, true);
        else
            $rule->from->removeZone($objectFind, true, true);
    },
    'args' => Array( 'zoneName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'from-Replace',
    'section' => 'zone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $f = RuleCallContext::$commonActionFunctions['zone-replace']['function'];
        $f($context, 'from');
    },
    'args' => & RuleCallContext::$commonActionFunctions['zone-replace']['args']
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'from-Set-Any',
    'section' => 'zone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ($rule->isPbfRule() && $rule->isZoneBased()) || ($rule->isDoSRule() && $rule->isZoneBasedFrom()) )
        {
            echo $context->padding." * SKIPPED: FROM is Zone based, not supported yet.\n";
            return;
        }

        if ($context->isAPI)
            $rule->from->API_setAny();
        else
            $rule->from->setAny();
    },
);

RuleCallContext::$supportedActions[] = Array(
    'name' => 'to-Add',
    'section' => 'zone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        if( $rule->isDoSRule() && $rule->isZoneBasedTo() )
        {
            echo $context->padding." * SKIPPED: TO is Zone based, not supported yet.\n";
            return;
        }
        if( $rule->isPbfRule() )
        {
            echo $context->padding." * SKIPPED: there is no TO in PBF Rules.\n";
            return;
        }

        $f = RuleCallContext::$commonActionFunctions['zone-add']['function'];
        $f($context, 'to', false);
    },
    'args' => &RuleCallContext::$commonActionFunctions['zone-add']['args'],
    'help' =>   "Adds a zone in the 'TO' field of a rule. If TO was set to ANY then it will be replaced by zone in argument.".
                "Zone must be existing already or script will out an error. Use action to-add-force if you want to add a zone that does not not exist."
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'to-Add-Force',
    'section' => 'zone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        if( $rule->isDoSRule() && $rule->isZoneBasedTo() )
        {
            echo $context->padding." * SKIPPED: TO is Zone based, not supported yet.\n";
            return;
        }
        if( $rule->isPbfRule() )
        {
            echo $context->padding." * SKIPPED: there is no TO in PBF Rules.\n";
            return;
        }

        $f = RuleCallContext::$commonActionFunctions['zone-add']['function'];
        $f($context, 'to', true);
    },
    'args' => &RuleCallContext::$commonActionFunctions['zone-add']['args'],
    'help' =>   "Adds a zone in the 'FROM' field of a rule. If FROM was set to ANY then it will be replaced by zone in argument."
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'to-Remove',
    'section' => 'zone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( $rule->isDoSRule() && $rule->isZoneBasedTo() )
        {
            echo $context->padding." * SKIPPED: TO is Zone based, not supported yet.\n";
            return;
        }
        if( $rule->isPbfRule() )
        {
            echo $context->padding." * SKIPPED: there is no TO in PBF Rules.\n";
            return;
        }
        if( !$rule->to->hasZone($context->arguments['zoneName']) )
        {
            echo $context->padding." * SKIPPED : no zone with requested name was found";
            return;
        }

        $objectFind = $rule->from->parentCentralStore->find($context->arguments['zoneName']);
        if ($objectFind === null)
            derr("zone named '{$context->arguments['zoneName']}' not found");

        if ($context->isAPI)
            $rule->to->API_removeZone($objectFind);
        else
            $rule->to->removeZone($objectFind);
    },
    'args' => Array( 'zoneName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'to-Remove-Force-Any',
    'section' => 'zone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( $rule->isDoSRule() && $rule->isZoneBasedTo() )
        {
            echo $context->padding." * SKIPPED: TO is Zone based, not supported yet.\n";
            return;
        }
        if( $rule->isPbfRule() )
        {
            echo $context->padding." * SKIPPED: there is no TO in PBF Rules.\n";
            return;
        }
        if( !$rule->to->hasZone($context->arguments['zoneName']) )
        {
            echo $context->padding." * SKIPPED : no zone with requested name was found";
            return;
        }

        $objectFind = $rule->from->parentCentralStore->find($context->arguments['zoneName']);
        if( $objectFind === null )
            derr("zone named '{$context->arguments['zoneName']}' not found");

        if ($context->isAPI)
            $rule->to->API_removeZone($objectFind, true, true);
        else
            $rule->to->removeZone($objectFind, true, true);
    },
    'args' => Array( 'zoneName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'to-Replace',
    'section' => 'zone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $f = RuleCallContext::$commonActionFunctions['zone-replace']['function'];
        $f($context, 'to');
    },
    'args' => & RuleCallContext::$commonActionFunctions['zone-replace']['args']
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'to-Set-Any',
    'section' => 'zone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( $rule->isDoSRule() && $rule->isZoneBasedTo() )
        {
            echo $context->padding." * SKIPPED: TO is Zone based, not supported yet.\n";
            return;
        }
        if( $rule->isPbfRule() )
        {
            echo $context->padding." * SKIPPED: there is no TO in PBF Rules.\n";
            return;
        }

        if( $context->isAPI )
            $rule->to->API_setAny();
        else
            $rule->to->setAny();
    },
);

RuleCallContext::$supportedActions[] = Array(
    'name' => 'from-calculate-zones',
    'section' => 'zone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ($rule->isPbfRule() && $rule->isZoneBased()) || ($rule->isDoSRule() && $rule->isZoneBasedFrom()) )
        {
            echo $context->padding." * SKIPPED: FROM is Zone based, not supported yet.\n";
            return;
        }
        
        $f = RuleCallContext::$commonActionFunctions['calculate-zones']['function'];
        $f($context, 'from');
    },
    'args' => & RuleCallContext::$commonActionFunctions['calculate-zones']['args'],
    'help' => & RuleCallContext::$commonActionFunctions['calculate-zones']['help']
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'to-calculate-zones',
    'section' => 'zone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        if( $rule->isDoSRule() && $rule->isZoneBasedTo() )
        {
            echo $context->padding." * SKIPPED: TO is Zone based, not supported yet.\n";
            return;
        }
        if( $rule->isPbfRule() )
        {
            echo $context->padding." * SKIPPED: there is no TO in PBF Rules.\n";
            return;
        }

        $f = RuleCallContext::$commonActionFunctions['calculate-zones']['function'];
        $f($context, 'to');
    },
    'args' => & RuleCallContext::$commonActionFunctions['calculate-zones']['args'],
    'help' => & RuleCallContext::$commonActionFunctions['calculate-zones']['help']
);


//                                                    //
//                Source/Dest Based Actions           //
//                                                    //
RuleCallContext::$supportedActions[] = Array(
    'name' => 'src-Add',
    'section' => 'address',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $objectFind = $rule->source->parentCentralStore->find($context->arguments['objName']);
        if( $objectFind === null )
            derr("address-type object named '{$context->arguments['objName']}' not found");

        if( $context->isAPI )
            $rule->source->API_add($objectFind);
        else
            $rule->source->addObject($objectFind);
    },
    'args' => Array( 'objName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
    'help' => "adds an object in the 'SOURCE' field of a rule, if that field was set to 'ANY' it will then be replaced by this object."
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'src-Remove',
    'section' => 'address',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $objectFind = $rule->source->parentCentralStore->find($context->arguments['objName']);
        if( $objectFind === null )
            derr("address-type object named '{$context->arguments['objName']}' not found");

        if( $context->isAPI )
            $rule->source->API_remove($objectFind);
        else
            $rule->source->remove($objectFind);
    },
    'args' => Array( 'objName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'src-Remove-Force-Any',
    'section' => 'address',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $objectFind = $rule->source->parentCentralStore->find($context->arguments['objName']);
        if( $objectFind === null )
            derr("address-type object named '{$context->arguments['objName']}' not found");

        if( $context->isAPI )
            $rule->source->API_remove($objectFind, true);
        else
            $rule->source->remove($objectFind, true, true);
    },
    'args' => Array( 'objName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'dst-Add',
    'section' => 'address',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $objectFind = $rule->source->parentCentralStore->find($context->arguments['objName']);
        if( $objectFind === null )
            derr("address-type object named '{$context->arguments['objName']}' not found");

        if( $context->isAPI )
            $rule->destination->API_add($objectFind);
        else
            $rule->destination->addObject($objectFind);
    },
    'args' => Array( 'objName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
    'help' => "adds an object in the 'DESTINATION' field of a rule, if that field was set to 'ANY' it will then be replaced by this object."
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'dst-Remove',
    'section' => 'address',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $objectFind = $rule->source->parentCentralStore->find($context->arguments['objName']);
        if( $objectFind === null )
            derr("address-type object named '{$context->arguments['objName']}' not found");

        if( $context->isAPI )
            $rule->destination->API_remove($objectFind);
        else
            $rule->destination->remove($objectFind);
    },
    'args' => Array( 'objName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'dst-Remove-Force-Any',
    'section' => 'address',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $objectFind = $rule->source->parentCentralStore->find($context->arguments['objName']);
        if( $objectFind === null )
            derr("address-type object named '{$context->arguments['objName']}' not found");

        if( $context->isAPI )
            $rule->destination->API_remove($objectFind, true);
        else
            $rule->destination->remove($objectFind, true, true);
    },
    'args' => Array( 'objName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'src-set-Any',
    'section' => 'address',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        if( $context->isAPI )
            $rule->source->API_setAny();
        else
            $rule->source->setAny();
    },
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'dst-set-Any',
    'section' => 'address',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        if( $context->isAPI )
            $rule->destination->API_setAny();
        else
            $rule->destination->setAny();
    },
);


RuleCallContext::$supportedActions[] = Array(
    'name' => 'src-Negate-Set',
    'section' => 'address',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( $context->isAPI )
            $rule->API_setSourceIsNegated($context->arguments['YESorNO']);
        else
            $rule->setSourceIsNegated($context->arguments['YESorNO']);
    },
    'args' => Array( 'YESorNO' => Array( 'type' => 'bool', 'default' => '*nodefault*' ) ),
    'help' => "manages Source Negation enablement"
);

RuleCallContext::$supportedActions[] = Array(
    'name' => 'dst-Negate-Set',
    'section' => 'address',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( $context->isAPI )
            $rule->API_setDestinationIsNegated($context->arguments['YESorNO']);
        else
            $rule->setDestinationIsNegated($context->arguments['YESorNO']);
    },
    'args' => Array( 'YESorNO' => Array( 'type' => 'bool', 'default' => '*nodefault*' ) ),
    'help' => "manages Destination Negation enablement"
);


//                                                 //
//              Tag property Based Actions         //
//                                                 //
RuleCallContext::$supportedActions[] = Array(
    'name' => 'tag-Add',
    'section' => 'tag',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $objectFind = $rule->tags->parentCentralStore->find($context->arguments['tagName']);
        if( $objectFind === null )
            derr("tag named '{$context->arguments['tagName']}' not found");

        if( $context->isAPI )
            $rule->tags->API_addTag($objectFind);
        else
            $rule->tags->addTag($objectFind);
    },
    'args' => Array( 'tagName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'tag-Add-Force',
    'section' => 'tag',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        if( $context->isAPI )
        {
            $objectFind = $rule->tags->parentCentralStore->find($context->arguments['tagName']);
            if( $objectFind === null)
                $objectFind = $rule->tags->parentCentralStore->API_createTag($context->arguments['tagName']);
        }
        else
            $objectFind = $rule->tags->parentCentralStore->findOrCreate($context->arguments['tagName']);

        if( $context->isAPI )
            $rule->tags->API_addTag($objectFind);
        else
            $rule->tags->addTag($objectFind);
    },
    'args' => Array( 'tagName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'tag-Remove',
    'section' => 'tag',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $objectFind = $rule->tags->parentCentralStore->find($context->arguments['tagName']);
        if( $objectFind === null )
            derr("tag named '{$context->arguments['tagName']}' not found");

        if( $context->isAPI )
            $rule->tags->API_removeTag($objectFind);
        else
            $rule->tags->removeTag($objectFind);
    },
    'args' => Array( 'tagName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'tag-Remove-All',
    'section' => 'tag',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        foreach($rule->tags->tags() as $tag )
        {
            echo $context->padding."  - removing tag {$tag->name()}... ";
            if( $context->isAPI )
                $rule->tags->API_removeTag($tag);
            else
                $rule->tags->removeTag($tag);
            echo "OK!\n";
        }
    },
    //'args' => Array( 'tagName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'tag-Remove-Regex',
    'section' => 'tag',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $pattern = '/'.$context->arguments['regex'].'/';
        foreach($rule->tags->tags() as $tag )
        {
            $result = preg_match($pattern, $tag->name());
            if( $result === false )
                derr("'$pattern' is not a valid regex");
            if( $result == 1 )
            {
                echo $context->padding."  - removing tag {$tag->name()}... ";
                if( $context->isAPI )
                    $rule->tags->API_removeTag($tag);
                else
                    $rule->tags->removeTag($tag);
                echo "OK!\n";
            }
        }
    },
    'args' => Array( 'regex' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);


//                                                   //
//                Services Based Actions             //
//                                                   //
RuleCallContext::$supportedActions[] = Array(
    'name' => 'service-Set-AppDefault',
    'section' => 'service',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( $context->isAPI )
            $rule->services->API_setApplicationDefault();
        else
            $rule->services->setApplicationDefault();
    },
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'service-Set-Any',
    'section' => 'service',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( $context->isAPI )
            $rule->services->API_setAny();
        else
            $rule->services->setAny();
    },
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'service-Add',
    'section' => 'service',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $objectFind = $rule->services->parentCentralStore->find($context->arguments['svcName']);
        if( $objectFind === null )
            derr("service named '{$context->arguments['svcName']}' not found");

        if( $context->isAPI )
            $rule->services->API_add($objectFind);
        else
            $rule->services->add($objectFind);
    },
    'args' => Array( 'svcName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'service-Remove',
    'section' => 'service',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $objectFind = $rule->services->parentCentralStore->find($context->arguments['svcName']);
        if( $objectFind === null )
            derr("service named '{$context->arguments['svcName']}' not found");

        if( $context->isAPI )
            $rule->services->API_remove($objectFind);
        else
            $rule->services->remove($objectFind);
    },
    'args' => Array( 'svcName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'service-Remove-Force-Any',
    'section' => 'service',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $objectFind = $rule->services->parentCentralStore->find($context->arguments['svcName']);
        if( $objectFind === null )
            derr("service named '{$context->arguments['svcName']}' not found");

        if( $context->isAPI )
            $rule->services->API_remove($objectFind, true, true);
        else
            $rule->services->remove($objectFind, true, true);
    },
    'args' => Array( 'svcName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);


//                                                   //
//                App Based Actions                  //
//                                                   //
RuleCallContext::$supportedActions[] = Array(
    'name' => 'app-Set-Any',
    'section' => 'app',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        if( $context->isAPI )
            $rule->apps->API_setAny();
        else
            $rule->apps->setAny();
    },
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'app-Add',
    'section' => 'app',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $objectFind = $rule->apps->parentCentralStore->find($context->arguments['appName']);
        if( $objectFind === null )
            derr("application named '{$context->arguments['appName']}' not found");

        if( $context->isAPI )
            $rule->apps->API_addApp($objectFind);
        else
            $rule->apps->addApp($objectFind);
    },
    'args' => Array( 'appName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'app-Remove',
    'section' => 'app',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $objectFind = $rule->apps->parentCentralStore->find($context->arguments['appName']);
        if( $objectFind === null )
            derr("application named '{$context->arguments['appName']}' not found");

        if( $context->isAPI )
            $rule->apps->API_removeApp($objectFind);
        else
            $rule->apps->removeApp($objectFind);
    },
    'args' => Array( 'appName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'app-Remove-Force-Any',
    'section' => 'app',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $objectFind = $rule->apps->parentCentralStore->find($context->arguments['appName']);
        if( $objectFind === null )
            derr("application named '{$context->arguments['appName']}' not found");

        if( $context->isAPI )
            $rule->apps->API_removeApp($objectFind, true, true);
        else
            $rule->apps->removeApp($objectFind, true, true);
    },
    'args' => Array( 'appName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);


//                                                 //
//               Target based Actions                 //
//                                                 //
RuleCallContext::$supportedActions[] = Array(
    'name' => 'target-Set-Any',
    'section' => 'target',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( $rule->target_isAny() )
        {
            print $context->padding."   * SKIPPED : target is already ANY\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_target_setAny();
        else
            $rule->target_setAny();
    },
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'target-Negate-Set',
    'section' => 'target',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( $rule->target_isNegated() == $context->arguments['trueOrFalse'] )
        {
            print $context->padding . "   * SKIPPED : target negation is already '" . boolYesNo($rule->target_isNegated()) . "''\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_target_negateSet($context->arguments['trueOrFalse']);
        else
            $rule->target_negateSet($context->arguments['trueOrFalse']);
    },
    'args' => Array(    'trueOrFalse' => Array( 'type' => 'bool', 'default' => '*nodefault*'  ) )
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'target-Add-Device',
    'section' => 'target',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        $vsys = null;
        if( $context->arguments['vsys'] != '*NULL*' )
            $vsys = $context->arguments['vsys'];
        $serial = $context->arguments['serial'];

        if( $rule->target_hasDeviceAndVsys( $serial ,$vsys) )
        {
            print $context->padding . "   * SKIPPED : firewall/vsys is already in the target\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_target_addDevice($serial, $vsys);
        else
            $rule->target_addDevice($serial, $vsys);

    },
    'args' => Array(    'serial' => Array( 'type' => 'string', 'default' => '*nodefault*'  ),
                        'vsys' => Array( 'type' => 'string', 'default' => '*NULL*'  )
                        ),
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'target-Remove-Device',
    'section' => 'target',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        $vsys = null;
        if( $context->arguments['vsys'] != '*NULL*' )
            $vsys = $context->arguments['vsys'];
        $serial = $context->arguments['serial'];

        if( ! $rule->target_hasDeviceAndVsys( $serial ,$vsys) )
        {
            print $context->padding . "   * SKIPPED : firewall/vsys does not have this Device/VSYS\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_target_removeDevice($serial, $vsys);
        else
            $rule->target_removeDevice($serial, $vsys);

    },
    'args' => Array(    'serial' => Array( 'type' => 'string', 'default' => '*nodefault*'  ),
        'vsys' => Array( 'type' => 'string', 'default' => '*NULL*'  )
    ),
);


//                                                 //
//               Log based Actions                 //
//                                                 //
RuleCallContext::$supportedActions[] = Array(
    'name' => 'logStart-Enable',
    'section' => 'log',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ! $rule->isSecurityRule() )
        {
            print $context->padding."   * SKIPPED : this is not a security rule";
            return;
        }

        if( $context->isAPI )
            $rule->API_setLogStart(true);
        else
            $rule->setLogStart(true);
    },
    'help' => 'disables "log at start" in a security rule'
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'logStart-Disable',
    'section' => 'log',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ! $rule->isSecurityRule() )
        {
            print $context->padding."   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setLogStart(false);
        else
            $rule->setLogStart(false);
    },
    'help' => 'enables "log at start" in a security rule'
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'logStart-Enable-FastAPI',
    'section' => 'log',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ! $rule->isSecurityRule() )
        {
            print $context->padding."   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( !$context->isAPI )
            derr("only supported in API mode!");

        if( $rule->setLogStart(true) )
        {
            print $context->padding." - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<log-start>yes</log-start>');
        }
    },
    'GlobalFinishFunction' => function(RuleCallContext $context)
    {
        $context->doBundled_API_Call();
    },
    'help' => "enables 'log at start' in a security rule.\n'FastAPI' allows API commands to be sent all at once instead of a single call per rule, allowing much faster execution time."
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'logStart-Disable-FastAPI',
    'section' => 'log',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ! $rule->isSecurityRule() )
        {
            print $context->padding."   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( !$context->isAPI )
            derr("only supported in API mode!");

        if( $rule->setLogStart(false) )
        {
            print $context->padding." - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<log-start>no</log-start>');
        }
    },
    'GlobalFinishFunction' => function(RuleCallContext $context)
    {
        $context->doBundled_API_Call();
    },
    'help' => "disables 'log at start' in a security rule.\n'FastAPI' allows API commands to be sent all at once instead of a single call per rule, allowing much faster execution time."
);

RuleCallContext::$supportedActions[] = Array(
    'name' => 'logEnd-Enable',
    'section' => 'log',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ! $rule->isSecurityRule() )
        {
            print $context->padding."   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setLogEnd(true);
        else
            $rule->setLogEnd(true);
    },
    'help' => "enables 'log at end' in a security rule."
);

RuleCallContext::$supportedActions[] = Array(
    'name' => 'logEnd-Disable',
    'section' => 'log',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ! $rule->isSecurityRule() )
        {
            print $context->padding."   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setLogEnd(false);
        else
            $rule->setLogEnd(false);
    },
    'help' => "disables 'log at end' in a security rule."
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'logend-Disable-FastAPI',
    'section' => 'log',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ! $rule->isSecurityRule() )
        {
            print $context->padding."   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( !$context->isAPI )
            derr("only supported in API mode!");

        if( $rule->setLogEnd(false) )
        {
            print $context->padding." - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<log-end>no</log-end>');
        }
    },
    'GlobalFinishFunction' => function(RuleCallContext $context)
    {
        $context->doBundled_API_Call();
    },
    'help' => "disables 'log at end' in a security rule.\n'FastAPI' allows API commands to be sent all at once instead of a single call per rule, allowing much faster execution time."
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'logend-Enable-FastAPI',
    'section' => 'log',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ! $rule->isSecurityRule() )
        {
            print $context->padding."   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( !$context->isAPI )
            derr("only supported in API mode!");

        if( $rule->setLogEnd(true) )
        {
            print $context->padding." - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<log-end>yes</log-end>');
        }
    },
    'GlobalFinishFunction' => function(RuleCallContext $context)
    {
        $context->doBundled_API_Call();
    },
    'help' => "enables 'log at end' in a security rule.\n'FastAPI' allows API commands to be sent all at once instead of a single call per rule, allowing much faster execution time."
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'logSetting-set',
    'section' => 'log',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ! $rule->isSecurityRule() )
        {
            print $context->padding."   * SKIPPED : this is not a security rule\n";
            return;
        }
        
        if( $context->isAPI )
            $rule->API_setLogSetting($context->arguments['profName']);
        else
            $rule->setLogSetting($context->arguments['profName']);
    },
    'args' => Array( 'profName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
    'help' => "Sets log setting/forwarding profile of a Security rule to the value specified."
);

RuleCallContext::$supportedActions[] = Array(
    'name' => 'logSetting-disable',
    'section' => 'log',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ! $rule->isSecurityRule() )
        {
            print $context->padding."   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setLogSetting(null);
        else
            $rule->setLogSetting(null);
    },
    'help' => "Remove log setting/forwarding profile of a Security rule if any."
);



//                                                   //
//                Security profile Based Actions     //
//                                                   //
RuleCallContext::$supportedActions[] = Array(
    'name' => 'securityProfile-Group-Set',
    'MainFunction' =>  function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding."  - SKIPPED : this is not a Security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setSecurityProfileGroup($context->arguments['profName']);
        else
            $rule->setSecurityProfileGroup($context->arguments['profName']);
    },
    'args' => Array( 'profName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) )
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'securityProfile-Remove',
    'MainFunction' =>  function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding."  - SKIPPED : this is not a Security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_removeSecurityProfile();
        else
            $rule->removeSecurityProfile();
    },
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'securityProfile-Group-Set-FastAPI',
    'section' => 'log',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding." - SKIPPED : this is not a Security rule\n";
            return;
        }

        if( !$context->isAPI )
            derr("only supported in API mode!");

        if( $rule->setSecurityProfileGroup($context->arguments['profName']) )
        {
            print $context->padding." - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<profile-setting><group><member>' . $context->arguments['profName'] . '</member></group></profile-setting>');
        }
    },
    'GlobalFinishFunction' => function(RuleCallContext $context)
    {
        $context->doBundled_API_Call();
    },
    'args' => Array( 'profName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) )
);

RuleCallContext::$supportedActions[] = Array(
    'name' => 'description-Append',
    'MainFunction' =>  function(RuleCallContext $context)
    {
        $rule = $context->object;
        $description = ' '.$rule->description();

        $textToAppend = $context->arguments['text'];

        if( strlen($description) + strlen($textToAppend) > 253 )
        {
            print $context->padding." - SKIPPED : description is too long\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setDescription($description.$textToAppend);
        else
            $rule->setSecurityProfileGroup($description.$textToAppend);
    },
    'args' => Array( 'text' => Array( 'type' => 'string', 'default' => '*nodefault*' ) )
);


//                                                   //
//                Other property Based Actions       //
//                                                   //
RuleCallContext::$supportedActions[] = Array(
    'name' => 'enabled-Set',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        if( $context->isAPI )
            $rule->API_setEnabled($context->arguments['trueOrFalse']);
        else
            $rule->setEnabled($context->arguments['trueOrFalse']);
    },
    'args' => Array(    'trueOrFalse' => Array( 'type' => 'bool', 'default' => 'yes'  ) )
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'enabled-Set-FastAPI',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        if( !$context->isAPI )
            derr('you cannot call this action without API mode');

        if( $rule->setEnabled($context->arguments['trueOrFalse']) )
        {
            print $context->padding." - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<disabled>' . boolYesNo(!$context->arguments['trueOrFalse']) . '</disabled>');
        }
    },
    'GlobalFinishFunction' => function(RuleCallContext $context)
    {
        $context->doBundled_API_Call();
    },
    'args' => Array(    'trueOrFalse' => Array( 'type' => 'bool', 'default' => 'yes'  ) )
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'disabled-Set',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        if( $context->isAPI )
            $rule->API_setDisabled($context->arguments['trueOrFalse']);
        else
            $rule->setDisabled($context->arguments['trueOrFalse']);
    },
    'args' => Array(    'trueOrFalse' => Array( 'type' => 'bool', 'default' => 'yes'  ) )
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'disabled-Set-FastAPI',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        if( !$context->isAPI )
            derr('you cannot call this action without API mode');

        if( $rule->setDisabled($context->arguments['trueOrFalse']) )
        {
            print $context->padding." - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<disabled>' . boolYesNo($context->arguments['trueOrFalse']) . '</disabled>');
        }
    },
    'GlobalFinishFunction' => function(RuleCallContext $context)
    {
        $setString = $context->generateRuleMergedApuChangeString(true);
        if( $setString !== null )
        {
            print $context->padding . ' - sending API call for SHARED... ';
            $context->connector->sendSetRequest('/config/shared', $setString);
            print "OK!\n";
        }
        $setString = $context->generateRuleMergedApuChangeString(false);
        if( $setString !== null )
        {
            print $context->padding . ' - sending API call for Device-Groups... ';
            $context->connector->sendSetRequest("/config/devices/entry[@name='localhost.localdomain']", $setString);
            print "OK!\n";
        }
    },
    'args' => Array(    'trueOrFalse' => Array( 'type' => 'bool', 'default' => 'yes'  ) )
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'delete',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        if( $context->isAPI )
            $rule->owner->API_remove($rule);
        else
            $rule->owner->remove($rule);
    }
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'biDirNat-Split',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( ! $rule->isNatRule() )
        {
            print $context->padding." * SKIPPED it's not a NAT rule\n";
            return;
        }
        /** @var NatRule $rule */

        if( ! $rule->isBiDirectional() )
        {
            print $context->padding." * SKIPPED because NAT rule is not bi-directional\n";
            return;
        }


        $newName = $rule->owner->findAvailableName($rule->name(), $context->arguments['suffix']);

        $rule->setBiDirectional( false );

        // Now creating the reverse NAT rule
        $newRule = $rule->owner->newNatRule( $newName );
        $rule->owner->moveRuleAfter($newRule,$rule);
        $newRule->to->copy($rule->to);
        $newRule->destination->copy($rule->snathosts);
        $newRule->setService( $rule->service );
        $test = $rule->source->members();
        $newRule->setDNAT( reset( $test ) );
        $newRule->tags->copy( $rule->tags );
        $newRule->setDestinationInterface( $rule->destinationInterface() );

        if( $context->isAPI )
        {
            $newRule->API_sync();
            $rule->API_sync();
            $newRule->owner->API_moveRuleAfter($newRule,$rule);
        }

    },
    'args' => Array(  'suffix' => Array( 'type' => 'string', 'default' => '-DST'  ), )
);

RuleCallContext::$supportedActions[] = Array(
    'name' => 'name-Prepend',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        $newName = $context->arguments['text'].$rule->name();

        if( strlen($newName) > 31 )
        {
            print $context->padding." * SKIPPED because new name '{$newName}' is too long\n";
            return;
        }

        if( !$rule->owner->isRuleNameAvailable($newName) )
        {
            print $context->padding." * SKIPPED because name '{$newName}' is not available\n";
            return;
        }

        if( $context->isAPI )
        {
            $rule->API_setName($newName);
        }
        else
        {
            $rule->setName($newName);
        }
    },
    'args' => Array(  'text' => Array( 'type' => 'string', 'default' => '*nodefault*'  ), )
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'name-Append',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        $newName = $rule->name().$context->arguments['text'];

        if( strlen($newName) > 31 )
        {
            print $context->padding." * SKIPPED because new name '{$newName}' is too long\n";
            return;
        }

        if( !$rule->owner->isRuleNameAvailable($newName) )
        {
            print $context->padding." * SKIPPED because name '{$newName}' is not available\n";
            return;
        }

        if( $context->isAPI )
        {
            $rule->API_setName($newName);
        }
        else
        {
            $rule->setName($newName);
        }
    },
    'args' => Array(  'text' => Array( 'type' => 'string', 'default' => '*nodefault*'  ), )
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'ruleType-Change',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $newType = $context->arguments['text'];

        if( !$rule->isSecurityRule() )
        {
            print $context->padding." * SKIPPED it's not a security rule\n";
            return;
        }

        if( $context->isAPI )
        {
            $rule->API_setType($newType);
        }
        else
        {
            $rule->setType($newType);
        }

    },
    'args' => Array(  'text' => Array( 'type' => 'string', 'default' => '*nodefault*'  ), )
);

RuleCallContext::$supportedActions[] = Array(
    'name' => 'display',
    'MainFunction' => function(RuleCallContext $context)
    {
        $context->object->display(7);
    }
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'invertPreAndPost',
    'MainFunction' => function(RuleCallContext $context)
    {
        if( !$context->isAPI )
        {
            if( $context->object->isPreRule() )
                $context->object->owner->moveRuleToPostRulebase($context->object);
            else if( $context->object->isPostRule() )
                $context->object->owner->moveRuleToPreRulebase($context->object);
            else
                derr('unsupported');
        }
        else
        {
            if( $context->object->isPreRule() )
                $context->object->owner->API_moveRuleToPostRulebase($context->object);
            else if( $context->object->isPostRule() )
                $context->object->owner->API_moveRuleToPreRulebase($context->object);
            else
                derr('unsupported');
        }
    }
);


RuleCallContext::$supportedActions[] = Array(
    'name' => 'copy',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $args = &$context->arguments;
        $location = $args['location'];
        $pan = PH::findRootObjectOrDie($rule);;

        if( $args['preORpost'] == "post" )
            $preORpost = true;
        else
            $preORpost = false;


        /** @var RuleStore $ruleStore */
        $ruleStore = null;
        $variableName = $rule->storeVariableName();

        if( strtolower($location) == 'shared' )
        {
            if( $pan->isFirewall() )
                derr("Rules cannot be copied to SHARED location on a firewall, only in Panorama");

            $ruleStore = $pan->$variableName;
        }
        else
        {
            $sub = $pan->findSubSystemByName($location);
            if( $sub === null )
                derr("cannot find vsys or device group named '{$location}'");
            $ruleStore = $sub->$variableName;
        }
        if( $context->isAPI )
            $ruleStore->API_cloneRule($rule, null, $preORpost);
        else
            $ruleStore->cloneRule($rule, null, $preORpost);
    },
    'args' => Array(    'location' => Array( 'type' => 'string', 'default' => '*nodefault*'  ),
        'preORpost' => Array( 'type' => 'string', 'default' => 'pre', 'choices' => Array('pre','post') ) )
);

RuleCallContext::$supportedActions[] = Array(
    'name' => 'move',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $args = &$context->arguments;
        $location = $args['location'];
        $pan = PH::findRootObjectOrDie($rule);;

        if( $args['preORpost'] == "post" )
            $preORpost = true;
        else
            $preORpost = false;


        /** @var RuleStore $ruleStore */
        $ruleStore = null;
        $variableName = $rule->storeVariableName();

        if( strtolower($location) == 'shared' )
        {
            if( $pan->isFirewall() )
                derr("Rules cannot be moved to SHARED location on a firewall, only in Panorama");

            $ruleStore = $pan->$variableName;
        }
        else
        {
            $sub = $pan->findSubSystemByName($location);
            if( $sub === null )
                derr("cannot find vsys or device group named '{$location}'");
            $ruleStore = $sub->$variableName;
        }
        if( $context->isAPI )
        {
            $ruleStore->API_cloneRule($rule, null, $preORpost);
            $rule->owner->API_remove($rule);
        }
        else
        {
            $ruleStore->cloneRule($rule, null, $preORpost);
            $rule->owner->remove($rule);
        }

    },
    'args' => Array(    'location' => Array( 'type' => 'string', 'default' => '*nodefault*'  ),
        'preORpost' => Array( 'type' => 'string', 'default' => 'pre', 'choices' => Array('pre','post') ) )
);

RuleCallContext::$supportedActions[] = Array(
    'name' => 'exportToExcel',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $context->ruleList[] = $rule;
    },
    'GlobalInitFunction' => function(RuleCallContext $context)
    {
        $context->ruleList = Array();
    },
    'GlobalFinishFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;
        $args = &$context->arguments;
        $filename = $args['filename'];

        $addResolvedAddressSummary = false;
        $fieldsArray = explode('|',$context->arguments['additionalFields']) ;
        foreach($fieldsArray as $fieldName)
        {
            $fieldName = strtolower($fieldName);
            if( $fieldName == 'resolveaddresssummary' )
                $addResolvedAddressSummary = true;
            else{
                if( $fieldName != '*none*')
                    derr("unsupported field name '{$fieldName}' when export to Excel/HTML");
            }
        }

        $fields = Array(
            'location' => 'location',
            'type' => 'type',
            'name' => 'name',
            'tag' => 'tags',
            'from' => 'from',
            'to' => 'to',
            'src' => 'source',
            'src_resolved_sum' => 'src_resolved_sum',
            'dst' => 'destination',
            'dst_resolved_sum' => 'dst_resolved_sum',
            'service' => 'service',
            'application' => 'application',
            'action' => 'action',
            'security' => 'security-profile',
            'disabled' => 'disabled',
            'src user' => 'src-user',
            'log start' => 'log_start',
            'log end' => 'log_end',
            'log prof' => 'log_profile',
            'snat type' => 'snat_type',
            'snat_address' => 'snat_address',
            'snat_address_resolved_sum' => 'snat_address_resolved_sum',
            'dnat_host' => 'dnat_host',
            'dnat_host_resolved_sum' => 'dnat_host_resolved_sum',
            'description' => 'description'
        );

        $lines = '';

        $count = 0;
        if( isset($context->ruleList) )
        {
            foreach ($context->ruleList as $rule)
            {
                $count++;

                /** @var SecurityRule|NatRule $rule */
                if ($count % 2 == 1)
                    $lines .= "<tr>\n";
                else
                    $lines .= "<tr bgcolor=\"#DDDDDD\">";

                foreach($fields as $fieldName => $fieldID )
                {
                    if( ($fieldName == 'src_resolved_sum' || $fieldName == 'dst_resolved_sum' ||
                            $fieldName == 'dnat_host_resolved_sum' || $fieldName == 'snat_address_resolved_sum' ) && !$addResolvedAddressSummary  )
                        continue;
                    $lines .= $context->ruleFieldHtmlExport($rule, $fieldID);
                }


                $lines .= "</tr>\n";

            }
        }



        $tableHeaders = '';
        foreach($fields as $fieldName => $value )
        {
            if( ($fieldName == 'src_resolved_sum' || $fieldName == 'dst_resolved_sum' ||
                    $fieldName == 'dnat_host_resolved_sum' || $fieldName == 'snat_address_resolved_sum' ) && !$addResolvedAddressSummary  )
                continue;
            $tableHeaders .= "<th>{$fieldName}</th>\n";
        }

        $content = file_get_contents(dirname(__FILE__).'/html-export-template.html');

        $content = str_replace('%TableHeaders%', $tableHeaders, $content);

        $content = str_replace('%lines%', $lines, $content);

        $jscontent =  file_get_contents(dirname(__FILE__).'/jquery-1.11.js');
        $jscontent .= "\n";
        $jscontent .= file_get_contents(dirname(__FILE__).'/jquery.stickytableheaders.min.js');
        $jscontent .= "\n\$('table').stickyTableHeaders();\n";

        $content = str_replace('%JSCONTENT%', $jscontent, $content);

        file_put_contents($filename, $content);
    },
    'args' => Array(
        'filename' => Array( 'type' => 'string', 'default' => '*nodefault*'  ),
        'additionalFields' =>
            Array( 'type' => 'string',
                'default' => '*NONE*',
                'help' =>
                    "pipe(|) separated list of additional field to include in the report. The following is available:\n".
                    "  - resolveAddressSummary : fields with address objects will be resolved and summarized in a new column)\n"
            )
    )
);

RuleCallContext::$supportedActions[] = Array(
    'name' => 'clone',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        $newName = $rule->owner->findAvailableName($rule->name(), $context->arguments['suffix']);

        print $context->padding."   - cloned rule name will be '{$newName}'\n";

        if( $context->isAPI )
        {
            $newRule = $rule->owner->API_cloneRule($rule, $newName);
            if( $context->arguments['before'] )
                $rule->owner->API_moveRuleBefore($newRule, $rule);
            else
                $rule->owner->API_moveRuleAfter($newRule, $rule);
        }
        else
        {
            $newRule = $rule->owner->cloneRule($rule, $newName);
            if( $context->arguments['before'] )
                $rule->owner->moveRuleBefore($newRule, $rule);
            else
                $rule->owner->moveRuleAfter($newRule, $rule);
        }

    },
    'args' => Array(    'before' => Array( 'type' => 'bool', 'default' => 'yes'  ),
                        'suffix' =>  Array( 'type' => 'string', 'default' => '-cloned' )
                    )
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'cloneForAppOverride',
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( $rule->actionIsNegative() )
        {
            print $context->padding . " - IGNORED because Action is DENY\n";
            return;
        }

        if( !$rule->apps->isAny() )
        {
            print $context->padding . " - IGNORED because Application is NOT EQUAL ANY\n";
            return;
        }

        $ports = '';

        if( ($rule->services->isAny() || $rule->services->isApplicationDefault()) && !$context->arguments['restrictToListOfServices'] == '*sameAsInRule*' )
        {
            $ports = '1-65535';
            $portMapping = ServiceDstPortMapping::mappingFromText($ports, true);
            $udpPortMapping = ServiceDstPortMapping::mappingFromText($ports, false);

            $portMapping->mergeWithMapping($udpPortMapping);
        }
        else
        {
            $portMapping = new ServiceDstPortMapping();

            if( $context->arguments['restrictToListOfServices'] == '*sameAsInRule*' )
            {
                $services = $rule->services->members();
            }
            else
            {
                $listOfServicesQueryName = $context->arguments['restrictToListOfServices'];
                if( !isset($context->nestedQueries[$listOfServicesQueryName]) )
                {
                    derr("cannot find query filter called '$listOfServicesQueryName'");
                }

                $rQuery = new RQuery('service');
                $errorMessage = '';
                if( !$rQuery->parseFromString($context->nestedQueries[$listOfServicesQueryName], $errorMessage) )
                    derr("error while parsing query: {$context->nestedQueries[$listOfServicesQueryName]}");

                $services = Array();

                foreach( $rule->services->membersExpanded() as $member )
                {
                    if( $rQuery->matchSingleObject($member) )
                    {
                        $services[] = $member;
                    }
                }
            }
            if( count($services) == 0)
            {
                print $context->padding." - IGNORED because NO MATCHING SERVICE FOUND\n";
                return;
            }
            $portMapping->mergeWithArrayOfServiceObjects($services);
        }

        $application = $rule->apps->parentCentralStore->findOrCreate($context->arguments['applicationName']);

        print $context->padding." - Port mapping to import in AppOverride: ".$portMapping->mappingToText()."\n";
        if( count($portMapping->tcpPortMap) > 0)
        {
            $newName = $rule->owner->owner->appOverrideRules->findAvailableName($rule->name(), '');
            $newRule = $rule->owner->owner->appOverrideRules->newAppOverrideRule($newName, $rule->isPostRule());
            if( $rule->sourceIsNegated() )
                $newRule->setSourceIsNegated(true);
            if( $rule->destinationIsNegated() )
                $newRule->setDestinationIsNegated(true);

            $newRule->from->copy($rule->from);
            $newRule->to->copy($rule->to);
            $newRule->source->copy($rule->source);
            $newRule->destination->copy($rule->destination);
            $newRule->setTcp();
            $newRule->setPorts($portMapping->tcpMappingToText());
            $newRule->setApplication($application);

            if( $context->isAPI )
                $newRule->API_sync();
            print $context->padding." - created TCP appOverride rule '{$newRule->name()}'\n";
        }
        if( count($portMapping->udpPortMap) > 0)
        {
            $newName = $rule->owner->owner->appOverrideRules->findAvailableName($rule->name(), '');
            $newRule = $rule->owner->owner->appOverrideRules->newAppOverrideRule($newName, $rule->isPreRule());
            if( $rule->sourceIsNegated() )
                $newRule->setSourceIsNegated(true);
            if( $rule->destinationIsNegated() )
                $newRule->setDestinationIsNegated(true);

            $newRule->from->copy($rule->from);
            $newRule->to->copy($rule->to);
            $newRule->source->copy($rule->source);
            $newRule->destination->copy($rule->destination);
            $newRule->setUdp();
            $newRule->setPorts($portMapping->udpMappingToText());
            $newRule->setApplication($application);

            if( $context->isAPI )
                $newRule->API_sync();
            print $context->padding." - created TCP appOverride rule '{$newRule->name()}'\n";
        }


    },
    'args' => Array(    'applicationName' => Array( 'type' => 'string', 'default' => '*nodefault*'  ),
        'restrictToListOfServices' => Array( 'type' => 'string', 'default' => '*sameAsInRule*'  ), )
);
// </editor-fold>
/************************************ */



