<?php
/**
 * ISC License
 *
 * Copyright (c) 2014-2018 Christophe Painchaud <shellescape _AT_ gmail.com>
 * Copyright (c) 2019, Palo Alto Networks Inc.
 *
 * Permission to use, copy, modify, and/or distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 *
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */

RuleCallContext::$commonActionFunctions['calculate-zones'] = array(
    'function' => function (RuleCallContext $context, $fromOrTo) {
        $rule = $context->object;

        $addrContainerIsNegated = FALSE;

        $zoneContainer = null;
        $addressContainer = null;

        if( $fromOrTo == 'from' )
        {
            $zoneContainer = $rule->from;
            $addressContainer = $rule->source;
            if( $rule->isSecurityRule() && $rule->sourceIsNegated() )
                $addrContainerIsNegated = TRUE;
        }
        elseif( $fromOrTo == 'to' )
        {
            $zoneContainer = $rule->to;
            $addressContainer = $rule->destination;
            if( $rule->isSecurityRule() && $rule->destinationIsNegated() )
                $addrContainerIsNegated = TRUE;
        }
        else
            derr('unsupported');

        $mode = $context->arguments['mode'];
        $system = $rule->owner->owner;

        /** @var VirtualRouter $virtualRouterToProcess */
        $virtualRouterToProcess = null;

        if( !isset($context->cachedIPmapping) )
            $context->cachedIPmapping = array();

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
                        if( !$doc->load($filename, XML_PARSE_BIG_LINES) )
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
                $foundRouters = array();

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
            print $context->padding . " - SKIPPED : address container is ANY()\n";
            return;
        }

        if( $rule->isSecurityRule() )
            $resolvedZones = &$addressContainer->calculateZonesFromIP4Mapping($ipMapping['ipv4'], $addrContainerIsNegated);
        else
            $resolvedZones = &$addressContainer->calculateZonesFromIP4Mapping($ipMapping['ipv4']);

        if( count($resolvedZones) == 0 )
        {
            print $context->padding . " - WARNING : no zone resolved (FQDN? IPv6?)\n";
            return;
        }


        $plus = array();
        foreach( $zoneContainer->zones() as $zone )
            $plus[$zone->name()] = $zone->name();

        $minus = array();
        $common = array();

        foreach( $resolvedZones as $zoneName => $zone )
        {
            if( isset($plus[$zoneName]) )
            {
                unset($plus[$zoneName]);
                $common[] = $zoneName;
                continue;
            }
            $minus[] = $zoneName;
        }

        if( count($common) > 0 )
            print $context->padding . " - untouched zones: " . PH::list_to_string($common) . "\n";
        if( count($minus) > 0 )
            print $context->padding . " - missing zones: " . PH::list_to_string($minus) . "\n";
        if( count($plus) > 0 )
            print $context->padding . " - unneeded zones: " . PH::list_to_string($plus) . "\n";

        if( $mode == 'replace' )
        {
            print $context->padding . " - REPLACE MODE, syncing with (" . count($resolvedZones) . ") resolved zones.";
            if( $addressContainer->isAny() )
                echo $context->padding . " *** IGNORED because value is 'ANY' ***\n";
            elseif( count($resolvedZones) == 0 )
                echo $context->padding . " *** IGNORED because no zone was resolved ***\n";
            elseif( count($minus) == 0 && count($plus) == 0 )
            {
                echo $context->padding . " *** IGNORED because there is no diff ***\n";
            }
            else
            {
                print "\n";

                if( $rule->isNatRule() && $fromOrTo == 'to' )
                {
                    if( count($common) > 0 )
                    {
                        foreach( $minus as $zoneToAdd )
                        {
                            $newRuleName = $rule->owner->findAvailableName($rule->name());
                            $newRule = $rule->owner->cloneRule($rule, $newRuleName);
                            $newRule->to->setAny();
                            $newRule->to->addZone($zoneContainer->parentCentralStore->findOrCreate($zoneToAdd));
                            echo $context->padding . " - cloned NAT rule with name '{$newRuleName}' and TO zone='{$zoneToAdd}'\n";
                            if( $context->isAPI )
                            {
                                $newRule->API_sync();
                                $newRule->owner->API_moveRuleAfter($newRule, $rule);
                            }
                            else
                                $newRule->owner->moveRuleAfter($newRule, $rule);
                        }
                        return;
                    }

                    $first = TRUE;
                    foreach( $minus as $zoneToAdd )
                    {
                        if( $first )
                        {
                            $rule->to->setAny();
                            $rule->to->addZone($zoneContainer->parentCentralStore->findOrCreate($zoneToAdd));
                            echo $context->padding . " - changed original NAT 'TO' zone='{$zoneToAdd}'\n";
                            if( $context->isAPI )
                                $rule->to->API_sync();
                            $first = FALSE;
                            continue;
                        }
                        $newRuleName = $rule->owner->findAvailableName($rule->name());
                        $newRule = $rule->owner->cloneRule($rule, $newRuleName);
                        $newRule->to->setAny();
                        $newRule->to->addZone($zoneContainer->parentCentralStore->findOrCreate($zoneToAdd));
                        echo $context->padding . " - cloned NAT rule with name '{$newRuleName}' and TO zone='{$zoneToAdd}'\n";
                        if( $context->isAPI )
                        {
                            $newRule->API_sync();
                            $newRule->owner->API_moveRuleAfter($newRule, $rule);
                        }
                        else
                            $newRule->owner->moveRuleAfter($newRule, $rule);
                    }

                    return;
                }

                $zoneContainer->setAny();
                foreach( $resolvedZones as $zone )
                    $zoneContainer->addZone($zoneContainer->parentCentralStore->findOrCreate($zone));
                if( $context->isAPI )
                    $zoneContainer->API_sync();
            }
        }
        elseif( $mode == 'append' )
        {
            print $context->padding . " - APPEND MODE: adding missing (" . count($minus) . ") zones only.";

            if( $addressContainer->isAny() )
                print " *** IGNORED because value is 'ANY' ***\n";
            elseif( count($minus) == 0 )
                print " *** IGNORED because no missing zones were found ***\n";
            else
            {
                print "\n";

                if( $rule->isNatRule() && $fromOrTo == 'to' )
                {
                    foreach( $minus as $zoneToAdd )
                    {
                        $newRuleName = $rule->owner->findAvailableName($rule->name());
                        $newRule = $rule->owner->cloneRule($rule, $newRuleName);
                        $newRule->to->setAny();
                        $newRule->to->addZone($zoneContainer->parentCentralStore->findOrCreate($zoneToAdd));
                        echo $context->padding . " - cloned NAT rule with name '{$newRuleName}' and TO zone='{$zoneToAdd}'\n";
                        if( $context->isAPI )
                        {
                            $newRule->API_sync();
                            $newRule->owner->API_moveRuleAfter($newRule, $rule);
                        }
                        else
                            $newRule->owner->moveRuleAfter($newRule, $rule);
                    }
                    return;
                }

                foreach( $minus as $zone )
                    $zoneContainer->addZone($zoneContainer->parentCentralStore->findOrCreate($zone));
                if( $context->isAPI )
                    $zoneContainer->API_sync();
            }
        }
        elseif( $mode == 'unneeded-tag-add' )
        {
            print $context->padding . " - UNNEEDED-TAG-ADD MODE: adding rule tag for unneeded zones.";

            if( $addressContainer->isAny() )
                print " *** IGNORED because value is 'ANY' ***\n";
            elseif( count($plus) == 0 )
                print " *** IGNORED because no unneeded zones were found ***\n";
            else
            {
                print "\n";

                if( $rule->isNatRule() && $fromOrTo == 'to' )
                {
                    derr($context->padding . ' NAT rules are not supported yet');
                }

                if( $fromOrTo == 'from' )
                    $tag_add = 'unneeded-from-zone';
                elseif( $fromOrTo == 'to' )
                    $tag_add = 'unneeded-to-zone';

                $objectFind = $rule->tags->parentCentralStore->findOrCreate($tag_add);
                $rule->tags->addTag($objectFind);

                if( $context->isAPI )
                    $zoneContainer->API_sync();
            }
        }
    },
    'args' => array('mode' => array('type' => 'string',
        'default' => 'append',
        'choices' => array('replace', 'append', 'show', 'unneeded-tag-add'),
        'help' => "Will determine what to do with resolved zones : show them, replace them in the rule" .
            " , only append them (removes none but adds missing ones) or tag-add for unneeded zones"
    ),
        'virtualRouter' => array('type' => 'string',
            'default' => '*autodetermine*',
            'help' => "Can optionally be provided if script cannot find which virtualRouter it should be using" .
                " (ie: there are several VR in same VSYS)"
        ),
        'template' => array('type' => 'string',
            'default' => '*notPanorama*',
            'help' => "When you are using Panorama then 1 or more templates could apply to a DeviceGroup, in" .
                " such a case you may want to specify which Template name to use.\nBeware that if the Template is overriden" .
                " or if you are not using Templates then you will want load firewall config in lieu of specifying a template." .
                " \nFor this, give value 'api@XXXXX' where XXXXX is serial number of the Firewall device number you want to use to" .
                " calculate zones.\nIf you don't want to use API but have firewall config file on your computer you can then" .
                " specify file@/folderXYZ/config.xml."
        ),
        'vsys' => array('type' => 'string',
            'default' => '*autodetermine*',
            'help' => "specify vsys when script cannot autodetermine it or when you when to manually override"
        ),
    ),
    'help' => "This Action will use routing tables to resolve zones. When the program cannot find all parameters by" .
        " itself (like vsys or template name you will have ti manually provide them.\n\n" .
        "Usage examples:\n\n" .
        "    - xxx-calculate-zones\n" .
        "    - xxx-calculate-zones:replace\n" .
        "    - xxx-calculate-zones:append,vr1\n" .
        "    - xxx-calculate-zones:replace,vr3,api@0011C890C,vsys1\n" .
        "    - xxx-calculate-zones:show,vr5,Datacenter_template\n" .
        "    - xxx-calculate-zones:replace,vr3,file@firewall.xml,vsys1\n"
);

RuleCallContext::$commonActionFunctions['zone-add'] = array(
    'function' => function (RuleCallContext $context, $fromOrTo, $force) {
        $rule = $context->object;

        $zoneContainer = null;

        if( $fromOrTo == 'from' )
        {
            $zoneContainer = $rule->from;
        }
        elseif( $fromOrTo == 'to' )
        {
            $zoneContainer = $rule->to;
        }
        else
            derr('unsupported');

        $objectFind = $zoneContainer->parentCentralStore->find($context->arguments['zoneName']);
        if( $objectFind === null && $force == FALSE )
            derr("zone named '{$context->arguments['zoneName']}' not found, you can try to use xxx-add-force action instead");

        $objectFind = $zoneContainer->parentCentralStore->findOrCreate($context->arguments['zoneName']);
        if( $objectFind === null )
            derr("zone named '{$context->arguments['zoneName']}' not found");

        if( $context->isAPI )
            $zoneContainer->API_addZone($objectFind);
        else
            $zoneContainer->addZone($objectFind);

    },
    'args' => array('zoneName' => array('type' => 'string', 'default' => '*nodefault*')),
);

RuleCallContext::$commonActionFunctions['zone-replace'] = array(
    'function' => function (RuleCallContext $context, $fromOrTo) {
        $rule = $context->object;

        $zoneNameToReplace = $context->arguments['zoneToReplaceName'];
        $zoneNameForReplacement = $context->arguments['zoneForReplacementName'];
        $force = $context->arguments['force'];

        /** @var ZoneRuleContainer $zoneContainer */
        $zoneContainer = null;

        if( $fromOrTo == 'from' )
        {
            if( $rule->isPbfRule() && $rule->isInterfaceBased()
                || $rule->isDoSRule() && !$rule->isZoneBasedFrom() )
            {
                echo $context->padding . " * SKIPPED : TO is Interface based.\n";
                return;
            }
            $zoneContainer = $rule->from;
        }
        elseif( $fromOrTo == 'to' )
        {
            if( $rule->isPbfRule() )
            {
                echo $context->padding . " * SKIPPED : there is no TO in PBF rules.\n";
                return;
            }
            if( $rule->isDoSRule() && !$rule->isZoneBasedTo() )
            {
                echo $context->padding . " * SKIPPED : TO is Interface based.\n";
                return;
            }
            $zoneContainer = $rule->to;
        }
        else
            derr('unsupported');


        $zoneToReplace = $zoneContainer->parentCentralStore->find($zoneNameToReplace);
        if( $zoneToReplace === null )
            derr("zone '{$zoneNameToReplace}' does not exist. If it's intended then please use a REGEXP instead\n");

        if( !$zoneContainer->hasZone($zoneToReplace) )
        {
            print $context->padding . " * SKIPPED : no zone with that name in the container\n";
            return;
        }

        $zoneForReplacement = $zoneContainer->parentCentralStore->find($zoneNameForReplacement);
        if( $zoneForReplacement === null )
        {
            if( !$force )
                derr("zone '{$zoneNameForReplacement}' does not exist. If it's intended then please use option force=TRUE to bypass this safeguard\n");
            $zoneForReplacement = $zoneContainer->parentCentralStore->createTmp($zoneNameForReplacement);
        }

        if( $context->isAPI )
        {
            if( $fromOrTo == 'to' && $rule->isNatRule() )
            {
                $zoneContainer->addZone($zoneForReplacement);
                $zoneContainer->removeZone($zoneToReplace);
                $connector = findConnectorOrDie($rule);
                $connector->sendEditRequest(DH::elementToPanXPath($zoneContainer->xmlroot), $zoneContainer->xmlroot);
            }
            elseif( $fromOrTo == 'from' && $rule->isPbfRule() )
            {
                $zoneContainer->addZone($zoneForReplacement);
                $zoneContainer->removeZone($zoneToReplace);
                $connector = findConnectorOrDie($rule);
                $connector->sendEditRequest(DH::elementToPanXPath($zoneContainer->xmlroot), $zoneContainer->xmlroot);
            }
            else
            {
                $zoneContainer->API_addZone($zoneForReplacement);
                $zoneContainer->API_removeZone($zoneToReplace);
            }

        }
        else
        {
            $zoneContainer->addZone($zoneForReplacement);
            $zoneContainer->removeZone($zoneToReplace);
        }

    },
    'args' => array('zoneToReplaceName' => array('type' => 'string', 'default' => '*nodefault*'),
        'zoneForReplacementName' => array('type' => 'string', 'default' => '*nodefault*'),
        'force' => array('type' => 'bool', 'default' => 'no')
    )
);

/***************************************
 *
 *         Supported Actions
 *
 **************************************/
$supportedActions = array();


// <editor-fold desc="Supported Actions Array" defaultstate="collapsed" >

//                                              //
//                Zone Based Actions            //
//                                              //
RuleCallContext::$supportedActions[] = array(
    'name' => 'from-Add',
    'section' => 'zone',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        if( ($rule->isPbfRule() && $rule->isZoneBased()) || ($rule->isDoSRule() && $rule->isZoneBasedFrom()) )
        {
            echo $context->padding . " * SKIPPED: FROM is Zone based, not supported yet.\n";
            return;
        }


        $f = RuleCallContext::$commonActionFunctions['zone-add']['function'];
        $f($context, 'from', FALSE);
    },
    'args' => & RuleCallContext::$commonActionFunctions['zone-add']['args'],
    'help' => "Adds a zone in the 'FROM' field of a rule. If FROM was set to ANY then it will be replaced by zone in argument." .
        "Zone must be existing already or script will out an error. Use action from-add-force if you want to add a zone that does not not exist."
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'from-Add-Force',
    'section' => 'zone',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        if( ($rule->isPbfRule() && $rule->isZoneBased()) || ($rule->isDoSRule() && $rule->isZoneBasedFrom()) )
        {
            echo $context->padding . " * SKIPPED: FROM is Zone based, not supported yet.\n";
            return;
        }
        $f = RuleCallContext::$commonActionFunctions['zone-add']['function'];
        $f($context, 'from', TRUE);
    },
    'args' => &RuleCallContext::$commonActionFunctions['zone-add']['args'],
    'help' => "Adds a zone in the 'FROM' field of a rule. If FROM was set to ANY then it will be replaced by zone in argument."
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'from-Remove',
    'section' => 'zone',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( ($rule->isPbfRule() && $rule->isZoneBased()) || ($rule->isDoSRule() && $rule->isZoneBasedFrom()) )
        {
            echo $context->padding . " * SKIPPED: FROM is Zone based, not supported yet.\n";
            return;
        }
        if( !$rule->from->hasZone($context->arguments['zoneName']) )
        {
            echo $context->padding . " * SKIPPED : no zone with requested name was found";
            return;
        }

        $objectFind = $rule->from->parentCentralStore->find($context->arguments['zoneName']);
        if( $objectFind === null )
            derr("zone named '{$context->arguments['zoneName']}' not found");

        if( $context->isAPI )
            $rule->from->API_removeZone($objectFind);
        else
            $rule->from->removeZone($objectFind);
    },
    'args' => array('zoneName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'from-Remove-Force-Any',
    'section' => 'zone',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( ($rule->isPbfRule() && $rule->isZoneBased()) || ($rule->isDoSRule() && $rule->isZoneBasedFrom()) )
        {
            echo $context->padding . " * SKIPPED: FROM is Zone based, not supported yet.\n";
            return;
        }
        if( !$rule->from->hasZone($context->arguments['zoneName']) )
        {
            echo $context->padding . " * SKIPPED : no zone with requested name was found";
            return;
        }

        $objectFind = $rule->from->parentCentralStore->find($context->arguments['zoneName']);
        if( $objectFind === null )
            derr("zone named '{$context->arguments['zoneName']}' not found");

        if( $context->isAPI )
            $rule->from->API_removeZone($objectFind, TRUE, TRUE);
        else
            $rule->from->removeZone($objectFind, TRUE, TRUE);
    },
    'args' => array('zoneName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'from-Replace',
    'section' => 'zone',
    'MainFunction' => function (RuleCallContext $context) {
        $f = RuleCallContext::$commonActionFunctions['zone-replace']['function'];
        $f($context, 'from');
    },
    'args' => & RuleCallContext::$commonActionFunctions['zone-replace']['args']
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'from-Set-Any',
    'section' => 'zone',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( ($rule->isPbfRule() && $rule->isZoneBased()) || ($rule->isDoSRule() && $rule->isZoneBasedFrom()) )
        {
            echo $context->padding . " * SKIPPED: FROM is Zone based, not supported yet.\n";
            return;
        }

        if( $context->isAPI )
            $rule->from->API_setAny();
        else
            $rule->from->setAny();
    },
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'to-Add',
    'section' => 'zone',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        if( $rule->isDoSRule() && $rule->isZoneBasedTo() )
        {
            echo $context->padding . " * SKIPPED: TO is Zone based, not supported yet.\n";
            return;
        }
        if( $rule->isPbfRule() )
        {
            echo $context->padding . " * SKIPPED: there is no TO in PBF Rules.\n";
            return;
        }

        $f = RuleCallContext::$commonActionFunctions['zone-add']['function'];
        $f($context, 'to', FALSE);
    },
    'args' => &RuleCallContext::$commonActionFunctions['zone-add']['args'],
    'help' => "Adds a zone in the 'TO' field of a rule. If TO was set to ANY then it will be replaced by zone in argument." .
        "Zone must be existing already or script will out an error. Use action to-add-force if you want to add a zone that does not not exist."
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'to-Add-Force',
    'section' => 'zone',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        if( $rule->isDoSRule() && $rule->isZoneBasedTo() )
        {
            echo $context->padding . " * SKIPPED: TO is Zone based, not supported yet.\n";
            return;
        }
        if( $rule->isPbfRule() )
        {
            echo $context->padding . " * SKIPPED: there is no TO in PBF Rules.\n";
            return;
        }

        $f = RuleCallContext::$commonActionFunctions['zone-add']['function'];
        $f($context, 'to', TRUE);
    },
    'args' => &RuleCallContext::$commonActionFunctions['zone-add']['args'],
    'help' => "Adds a zone in the 'FROM' field of a rule. If FROM was set to ANY then it will be replaced by zone in argument."
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'to-Remove',
    'section' => 'zone',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( $rule->isDoSRule() && $rule->isZoneBasedTo() )
        {
            echo $context->padding . " * SKIPPED: TO is Zone based, not supported yet.\n";
            return;
        }
        if( $rule->isPbfRule() )
        {
            echo $context->padding . " * SKIPPED: there is no TO in PBF Rules.\n";
            return;
        }
        if( !$rule->to->hasZone($context->arguments['zoneName']) )
        {
            echo $context->padding . " * SKIPPED : no zone with requested name was found";
            return;
        }

        $objectFind = $rule->from->parentCentralStore->find($context->arguments['zoneName']);
        if( $objectFind === null )
            derr("zone named '{$context->arguments['zoneName']}' not found");

        if( $context->isAPI )
            $rule->to->API_removeZone($objectFind);
        else
            $rule->to->removeZone($objectFind);
    },
    'args' => array('zoneName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'to-Remove-Force-Any',
    'section' => 'zone',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( $rule->isDoSRule() && $rule->isZoneBasedTo() )
        {
            echo $context->padding . " * SKIPPED: TO is Zone based, not supported yet.\n";
            return;
        }
        if( $rule->isPbfRule() )
        {
            echo $context->padding . " * SKIPPED: there is no TO in PBF Rules.\n";
            return;
        }
        if( !$rule->to->hasZone($context->arguments['zoneName']) )
        {
            echo $context->padding . " * SKIPPED : no zone with requested name was found";
            return;
        }

        $objectFind = $rule->from->parentCentralStore->find($context->arguments['zoneName']);
        if( $objectFind === null )
            derr("zone named '{$context->arguments['zoneName']}' not found");

        if( $context->isAPI )
            $rule->to->API_removeZone($objectFind, TRUE, TRUE);
        else
            $rule->to->removeZone($objectFind, TRUE, TRUE);
    },
    'args' => array('zoneName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'to-Replace',
    'section' => 'zone',
    'MainFunction' => function (RuleCallContext $context) {
        $f = RuleCallContext::$commonActionFunctions['zone-replace']['function'];
        $f($context, 'to');
    },
    'args' => & RuleCallContext::$commonActionFunctions['zone-replace']['args']
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'to-Set-Any',
    'section' => 'zone',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( $rule->isDoSRule() && $rule->isZoneBasedTo() )
        {
            echo $context->padding . " * SKIPPED: TO is Zone based, not supported yet.\n";
            return;
        }
        if( $rule->isPbfRule() )
        {
            echo $context->padding . " * SKIPPED: there is no TO in PBF Rules.\n";
            return;
        }

        if( $context->isAPI )
            $rule->to->API_setAny();
        else
            $rule->to->setAny();
    },
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'from-calculate-zones',
    'section' => 'zone',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( ($rule->isPbfRule() && $rule->isZoneBased()) || ($rule->isDoSRule() && $rule->isZoneBasedFrom()) )
        {
            echo $context->padding . " * SKIPPED: FROM is Zone based, not supported yet.\n";
            return;
        }

        #$f = RuleCallContext::$commonActionFunctions['calculate-zones']['function'];
        #$f($context, 'from');
        $rule->zoneCalculation('from', $context->arguments['mode'], $context->arguments['virtualRouter'], $context->arguments['template'], $context->arguments['vsys']);

    },
    'args' => & RuleCallContext::$commonActionFunctions['calculate-zones']['args'],
    'help' => & RuleCallContext::$commonActionFunctions['calculate-zones']['help']
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'to-calculate-zones',
    'section' => 'zone',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        if( $rule->isDoSRule() && $rule->isZoneBasedTo() )
        {
            echo $context->padding . " * SKIPPED: TO is Zone based, not supported yet.\n";
            return;
        }
        if( $rule->isPbfRule() )
        {
            echo $context->padding . " * SKIPPED: there is no TO in PBF Rules.\n";
            return;
        }

        #$f = RuleCallContext::$commonActionFunctions['calculate-zones']['function'];
        #$f($context, 'to');
        $rule->zoneCalculation('to', $context->arguments['mode'], $context->arguments['virtualRouter'], $context->arguments['template'], $context->arguments['vsys']);
    },
    'args' => & RuleCallContext::$commonActionFunctions['calculate-zones']['args'],
    'help' => & RuleCallContext::$commonActionFunctions['calculate-zones']['help']
);


//                                                    //
//                Source/Dest Based Actions           //
//                                                    //
RuleCallContext::$supportedActions[] = array(
    'name' => 'src-Add',
    'section' => 'address',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $objectFind = $rule->source->parentCentralStore->find($context->arguments['objName']);
        if( $objectFind === null )
            derr("address-type object named '{$context->arguments['objName']}' not found");

        if( $context->isAPI )
            $rule->source->API_add($objectFind);
        else
            $rule->source->addObject($objectFind);
    },
    'args' => array('objName' => array('type' => 'string', 'default' => '*nodefault*')),
    'help' => "adds an object in the 'SOURCE' field of a rule, if that field was set to 'ANY' it will then be replaced by this object."
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'src-Remove',
    'section' => 'address',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $objectFind = $rule->source->parentCentralStore->find($context->arguments['objName']);
        if( $objectFind === null )
            derr("address-type object named '{$context->arguments['objName']}' not found");

        if( $context->isAPI )
            $rule->source->API_remove($objectFind);
        else
            $rule->source->remove($objectFind);
    },
    'args' => array('objName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'src-Remove-Objects-Matching-Filter',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( $rule->source->count() < 1 )
            return;

        $queryName = $context->arguments['filterName'];

        if( !isset($context->nestedQueries[$queryName]) )
        {
            derr("cannot find query filter called '{$queryName}'");
        }

        $rQuery = new RQuery('address');
        $errorMessage = '';
        if( !$rQuery->parseFromString($context->nestedQueries[$queryName], $errorMessage) )
            derr("error while parsing query: {$context->nestedQueries[$queryName]}");


        foreach( $rule->source->members() as $member )
        {
            if( $rQuery->matchSingleObject($member) )
            {
                echo $context->padding . "  - removing object '{$member->name()}'... ";
                if( $context->isAPI )
                    $rule->source->API_remove($member, TRUE);
                else
                    $rule->source->remove($member, TRUE, TRUE);
                echo "OK\n";
            }
        }

        if( $rule->source->count() < 1 )
        {
            echo $context->padding . " * no objects remaining so the Rule will be disabled...";
            if( $context->isAPI )
                $rule->API_setDisabled(TRUE);
            else
                $rule->setDisabled(TRUE);
            echo "OK\n";
        }


    },
    'args' => array('filterName' => array('type' => 'string', 'default' => '*nodefault*',
        'help' => 'specify the query that will be used to filter the objects to be removed'),
    ),
    'help' => "this action will go through all objects and see if they match the query you input and then remove them if it's the case."
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'src-dst-swap',
    'section' => 'address',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        $old_srcs = $rule->source->getAll();
        $old_dsts = $rule->destination->getAll();
        $old_tos = $rule->to->getAll();
        $old_froms = $rule->from->getAll();


        foreach( $old_srcs as $old_src )
        {
            $rule->source->remove($old_src, TRUE, TRUE);
            $rule->destination->addObject($old_src);
        }
        foreach( $old_dsts as $old_dst )
        {
            $rule->destination->remove($old_dst, TRUE, TRUE);
            $rule->source->addObject($old_dst);
        }
        foreach( $old_froms as $old_from )
        {
            $rule->from->removeZone($old_from, TRUE, TRUE);
            $rule->to->addZone($old_from);
        }
        foreach( $old_tos as $old_to )
        {
            $rule->to->removeZone($old_to, TRUE, TRUE);
            $rule->from->addZone($old_to);
        }

        if( $context->isAPI )
        {
            $rule->API_sync();
        }
    },
    'help' => "moves all source objects to destination and reverse."
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'dst-Remove-Objects-Matching-Filter',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( $rule->destination->count() < 1 )
            return;

        $queryName = $context->arguments['filterName'];

        if( !isset($context->nestedQueries[$queryName]) )
        {
            derr("cannot find query filter called '{$queryName}'");
        }

        $rQuery = new RQuery('address');
        $errorMessage = '';
        if( !$rQuery->parseFromString($context->nestedQueries[$queryName], $errorMessage) )
            derr("error while parsing query: {$context->nestedQueries[$queryName]}\nError Message is: {$errorMessage}\n");


        foreach( $rule->destination->members() as $member )
        {
            if( $rQuery->matchSingleObject($member) )
            {
                echo $context->padding . "  - removing object '{$member->name()}'... ";
                if( $context->isAPI )
                    $rule->destination->API_remove($member, TRUE);
                else
                    $rule->destination->remove($member, TRUE);
                echo "OK\n";
            }
        }

        if( $rule->destination->count() < 1 )
        {
            echo $context->padding . " * no objects remaining so the Rule will be disabled...";
            if( $context->isAPI )
                $rule->API_setDisabled(TRUE);
            else
                $rule->setDisabled(TRUE);
            echo "OK\n";
        }


    },
    'args' => array('filterName' => array('type' => 'string', 'default' => '*nodefault*',
        'help' => 'specify the query that will be used to filter the objects to be removed'),
    ),
    'help' => "this action will go through all objects and see if they match the query you input and then remove them if it's the case."
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'service-Remove-Objects-Matching-Filter',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( $rule->services->count() < 1 )
            return;

        $queryName = $context->arguments['filterName'];
        $forceAny = $context->arguments['forceAny'];

        if( !isset($context->nestedQueries[$queryName]) )
        {
            derr("cannot find query filter called '{$queryName}'");
        }

        $rQuery = new RQuery('service');
        $errorMessage = '';
        if( !$rQuery->parseFromString($context->nestedQueries[$queryName], $errorMessage) )
            derr("error while parsing query: {$context->nestedQueries[$queryName]}\nError Message is: {$errorMessage}\n");


        foreach( $rule->services->members() as $member )
        {
            if( $rQuery->matchSingleObject($member) )
            {
                echo $context->padding . "  - removing object '{$member->name()}'... ";
                if( $context->isAPI )
                    $rule->services->API_remove($member, TRUE, $forceAny);
                else
                    $rule->services->remove($member, TRUE, $forceAny);
                echo "OK\n";
            }
        }

        if( $rule->services->count() < 1 )
        {
            echo $context->padding . " * no objects remaining so the Rule will be disabled...";
            if( $context->isAPI )
                $rule->API_setDisabled(TRUE);
            else
                $rule->setDisabled(TRUE);
            echo "OK\n";
        }


    },
    'args' => array(
        'filterName' => array('type' => 'string', 'default' => '*nodefault*',
            'help' => 'specify the query that will be used to filter the objects to be removed - 
example: \'actions=service-remove-objects-matching-filter:subquery1,true\' \'subquery1=(value > 600) && (object is.udp) && (value is.single.port)\''),
        'forceAny' => array('type' => 'bool', 'default' => 'false'),
    ),
    'help' => "this action will go through all objects and see if they match the query you input and then remove them if it's the case."
);


RuleCallContext::$supportedActions[] = array(
    'name' => 'src-Remove-Force-Any',
    'section' => 'address',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $objectFind = $rule->source->parentCentralStore->find($context->arguments['objName']);
        if( $objectFind === null )
            derr("address-type object named '{$context->arguments['objName']}' not found");

        if( $context->isAPI )
            $rule->source->API_remove($objectFind, TRUE);
        else
            $rule->source->remove($objectFind, TRUE, TRUE);
    },
    'args' => array('objName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'dst-Add',
    'section' => 'address',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $objectFind = $rule->source->parentCentralStore->find($context->arguments['objName']);
        if( $objectFind === null )
            derr("address-type object named '{$context->arguments['objName']}' not found");

        if( $context->isAPI )
            $rule->destination->API_add($objectFind);
        else
            $rule->destination->addObject($objectFind);
    },
    'args' => array('objName' => array('type' => 'string', 'default' => '*nodefault*')),
    'help' => "adds an object in the 'DESTINATION' field of a rule, if that field was set to 'ANY' it will then be replaced by this object."
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'dst-Remove',
    'section' => 'address',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $objectFind = $rule->source->parentCentralStore->find($context->arguments['objName']);
        if( $objectFind === null )
            derr("address-type object named '{$context->arguments['objName']}' not found");

        if( $context->isAPI )
            $rule->destination->API_remove($objectFind);
        else
            $rule->destination->remove($objectFind);
    },
    'args' => array('objName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'dst-Remove-Force-Any',
    'section' => 'address',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $objectFind = $rule->source->parentCentralStore->find($context->arguments['objName']);
        if( $objectFind === null )
            derr("address-type object named '{$context->arguments['objName']}' not found");

        if( $context->isAPI )
            $rule->destination->API_remove($objectFind, TRUE);
        else
            $rule->destination->remove($objectFind, TRUE, TRUE);
    },
    'args' => array('objName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'src-set-Any',
    'section' => 'address',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        if( $context->isAPI )
            $rule->source->API_setAny();
        else
            $rule->source->setAny();
    },
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'dst-set-Any',
    'section' => 'address',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        if( $context->isAPI )
            $rule->destination->API_setAny();
        else
            $rule->destination->setAny();
    },
);


RuleCallContext::$supportedActions[] = array(
    'name' => 'src-Negate-Set',
    'section' => 'address',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( $context->isAPI )
            $rule->API_setSourceIsNegated($context->arguments['YESorNO']);
        else
            $rule->setSourceIsNegated($context->arguments['YESorNO']);
    },
    'args' => array('YESorNO' => array('type' => 'bool', 'default' => '*nodefault*')),
    'help' => "manages Source Negation enablement"
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'dst-Negate-Set',
    'section' => 'address',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( $context->isAPI )
            $rule->API_setDestinationIsNegated($context->arguments['YESorNO']);
        else
            $rule->setDestinationIsNegated($context->arguments['YESorNO']);
    },
    'args' => array('YESorNO' => array('type' => 'bool', 'default' => '*nodefault*')),
    'help' => "manages Destination Negation enablement"
);


//                                                 //
//              Tag property Based Actions         //
//                                                 //
RuleCallContext::$supportedActions[] = array(
    'name' => 'tag-Add',
    'section' => 'tag',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $objectFind = $rule->tags->parentCentralStore->find($context->arguments['tagName']);
        if( $objectFind === null )
            derr("tag named '{$context->arguments['tagName']}' not found");

        if( $context->isAPI )
            $rule->tags->API_addTag($objectFind);
        else
            $rule->tags->addTag($objectFind);
    },
    'args' => array('tagName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'tag-Add-Force',
    'section' => 'tag',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        if( $context->isAPI )
        {
            $objectFind = $rule->tags->parentCentralStore->find($context->arguments['tagName']);
            if( $objectFind === null )
                $objectFind = $rule->tags->parentCentralStore->API_createTag($context->arguments['tagName']);
        }
        else
            $objectFind = $rule->tags->parentCentralStore->findOrCreate($context->arguments['tagName']);

        if( $context->arguments['tagColor'] != 'none' )
        {
            if( $context->isAPI )
                $objectFind->API_setColor($context->arguments['tagColor']);
            else
                $objectFind->setColor($context->arguments['tagColor']);
        }

        if( $context->isAPI )
            $rule->tags->API_addTag($objectFind);
        else
            $rule->tags->addTag($objectFind);
    },
    'args' => array('tagName' => array('type' => 'string', 'default' => '*nodefault*'), 'tagColor' => array('type' => 'string', 'default' => 'none')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'tag-Remove',
    'section' => 'tag',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $objectFind = $rule->tags->parentCentralStore->find($context->arguments['tagName']);
        if( $objectFind === null )
            derr("tag named '{$context->arguments['tagName']}' not found");

        if( $context->isAPI )
            $rule->tags->API_removeTag($objectFind);
        else
            $rule->tags->removeTag($objectFind);
    },
    'args' => array('tagName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'tag-Remove-All',
    'section' => 'tag',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        foreach( $rule->tags->tags() as $tag )
        {
            echo $context->padding . "  - removing tag {$tag->name()}... ";
            if( $context->isAPI )
                $rule->tags->API_removeTag($tag);
            else
                $rule->tags->removeTag($tag);
            echo "OK!\n";
        }
    },
    //'args' => Array( 'tagName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) ),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'tag-Remove-Regex',
    'section' => 'tag',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $pattern = '/' . $context->arguments['regex'] . '/';
        foreach( $rule->tags->tags() as $tag )
        {
            $result = preg_match($pattern, $tag->name());
            if( $result === FALSE )
                derr("'$pattern' is not a valid regex");
            if( $result == 1 )
            {
                echo $context->padding . "  - removing tag {$tag->name()}... ";
                if( $context->isAPI )
                    $rule->tags->API_removeTag($tag);
                else
                    $rule->tags->removeTag($tag);
                echo "OK!\n";
            }
        }
    },
    'args' => array('regex' => array('type' => 'string', 'default' => '*nodefault*')),
);


//                                                   //
//                Services Based Actions             //
//                                                   //
RuleCallContext::$supportedActions[] = array(
    'name' => 'service-Set-AppDefault',
    'section' => 'service',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( $context->isAPI )
            $rule->services->API_setApplicationDefault();
        else
            $rule->services->setApplicationDefault();
    },
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'service-Set-Any',
    'section' => 'service',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( $context->isAPI )
            $rule->services->API_setAny();
        else
            $rule->services->setAny();
    },
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'service-Add',
    'section' => 'service',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $objectFind = $rule->services->parentCentralStore->find($context->arguments['svcName']);
        if( $objectFind === null )
            derr("service named '{$context->arguments['svcName']}' not found");

        if( $context->isAPI )
            $rule->services->API_add($objectFind);
        else
            $rule->services->add($objectFind);
    },
    'args' => array('svcName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'service-Remove',
    'section' => 'service',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $objectFind = $rule->services->parentCentralStore->find($context->arguments['svcName']);
        if( $objectFind === null )
            derr("service named '{$context->arguments['svcName']}' not found");

        if( $context->isAPI )
            $rule->services->API_remove($objectFind);
        else
            $rule->services->remove($objectFind);
    },
    'args' => array('svcName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'service-Remove-Force-Any',
    'section' => 'service',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $objectFind = $rule->services->parentCentralStore->find($context->arguments['svcName']);
        if( $objectFind === null )
            derr("service named '{$context->arguments['svcName']}' not found");

        if( $context->isAPI )
            $rule->services->API_remove($objectFind, TRUE, TRUE);
        else
            $rule->services->remove($objectFind, TRUE, TRUE);
    },
    'args' => array('svcName' => array('type' => 'string', 'default' => '*nodefault*')),
);


//                                                   //
//                App Based Actions                  //
//                                                   //
RuleCallContext::$supportedActions[] = array(
    'name' => 'app-Set-Any',
    'section' => 'app',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        if( $context->isAPI )
            $rule->apps->API_setAny();
        else
            $rule->apps->setAny();
    },
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'app-Add',
    'section' => 'app',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        $appName = $context->arguments['appName'];

        $objectFind = $rule->apps->parentCentralStore->find($appName);
        if( $objectFind === null )
            derr("application named '{$appName}' not found");

        echo $context->padding . " - adding application '{$appName}'... ";

        if( $context->isAPI )
            $rule->apps->API_addApp($objectFind);
        else
            $rule->apps->addApp($objectFind);

        echo "OK\n";

    },
    'args' => array('appName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'app-Add-Force',
    'section' => 'app',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $objectFind = $rule->apps->parentCentralStore->findorCreate($context->arguments['appName']);
        if( $objectFind === null )
            derr("application named '{$context->arguments['appName']}' not found");

        if( $context->isAPI )
            $rule->apps->API_addApp($objectFind);
        else
            $rule->apps->addApp($objectFind);
    },
    'args' => array('appName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'app-Remove',
    'section' => 'app',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $objectFind = $rule->apps->parentCentralStore->find($context->arguments['appName']);
        if( $objectFind === null )
            derr("application named '{$context->arguments['appName']}' not found");

        if( $context->isAPI )
            $rule->apps->API_removeApp($objectFind);
        else
            $rule->apps->removeApp($objectFind);
    },
    'args' => array('appName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'app-Remove-Force-Any',
    'section' => 'app',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $objectFind = $rule->apps->parentCentralStore->find($context->arguments['appName']);
        if( $objectFind === null )
            derr("application named '{$context->arguments['appName']}' not found");

        if( $context->isAPI )
            $rule->apps->API_removeApp($objectFind, TRUE, TRUE);
        else
            $rule->apps->removeApp($objectFind, TRUE, TRUE);
    },
    'args' => array('appName' => array('type' => 'string', 'default' => '*nodefault*')),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'app-Fix-Dependencies',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
            return null;

        if( $rule->apps->count() < 1 )
            return null;

        if( !$rule->actionIsAllow() )
            return null;

        $app_depends_on = array();
        $app_array = array();
        foreach( $rule->apps->membersExpanded() as $app )
        {
            $app_array[$app->name()] = $app->name();
            foreach( $app->calculateDependencies() as $dependency )
            {
                $app_depends_on[$dependency->name()] = $dependency->name();
            }
        }

        foreach( $app_depends_on as $app => $dependencies )
        {
            if( !isset($app_array[$app]) )
            {
                $add_app = $rule->owner->owner->appStore->find($app);
                if( $context->arguments['fix'] )
                {
                    if( $context->isAPI )
                        $rule->apps->API_addApp($add_app);
                    else
                        $rule->apps->addApp($add_app);
                }

                print "        - app-id: " . $app . " is missing in rule\n";
            }
        }
    },
    'args' => array('fix' => array('type' => 'bool', 'default' => 'no'),)

);

RuleCallContext::$supportedActions[] = array(
    'name' => 'app-Usage-clear',
    'section' => 'app',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( $context->isAPI )
            $rule->API_clearPolicyAppUsageDATA();
        else
            print "only supported via API\n";
    },
);

/*
RuleCallContext::$supportedActions[] = Array(
    'name' => 'app-Usage-clear-FastAPI',
    'section' => 'app',
    'GlobalInitFunction' => function(RuleCallContext $context)
    {
        $context->uuid = array();
    },
    'MainFunction' => function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( $context->isAPI )
            $context->uuid[] = $rule->uuid();
        else
            print "only supported via API\n";
    },
    'GlobalFinishFunction' => function(RuleCallContext $context)
    {
        $context->clearPolicyAppUsageDATA_doBundled_API_Call( $context->uuid );
    },
);
*/
//                                                 //
//               Target based Actions                 //
//                                                 //
RuleCallContext::$supportedActions[] = array(
    'name' => 'target-Set-Any',
    'section' => 'target',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( $rule->target_isAny() )
        {
            print $context->padding . "   * SKIPPED : target is already ANY\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_target_setAny();
        else
            $rule->target_setAny();
    },
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'target-Negate-Set',
    'section' => 'target',
    'MainFunction' => function (RuleCallContext $context) {
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
    'args' => array('trueOrFalse' => array('type' => 'bool', 'default' => '*nodefault*'))
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'target-Add-Device',
    'section' => 'target',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        $vsys = null;
        if( $context->arguments['vsys'] != '*NULL*' )
            $vsys = $context->arguments['vsys'];
        $serial = $context->arguments['serial'];

        if( $rule->target_hasDeviceAndVsys($serial, $vsys) )
        {
            print $context->padding . "   * SKIPPED : firewall/vsys is already in the target\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_target_addDevice($serial, $vsys);
        else
            $rule->target_addDevice($serial, $vsys);

    },
    'args' => array('serial' => array('type' => 'string', 'default' => '*nodefault*'),
        'vsys' => array('type' => 'string', 'default' => '*NULL*', 'help' => 'if target firewall is single VSYS you should ignore this argument, otherwise just input it')
    ),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'target-Remove-Device',
    'section' => 'target',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        $vsys = null;
        if( $context->arguments['vsys'] != '*NULL*' )
            $vsys = $context->arguments['vsys'];
        $serial = $context->arguments['serial'];

        if( !$rule->target_hasDeviceAndVsys($serial, $vsys) )
        {
            print $context->padding . "   * SKIPPED : firewall/vsys does not have this Device/VSYS\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_target_removeDevice($serial, $vsys);
        else
            $rule->target_removeDevice($serial, $vsys);

    },
    'args' => array('serial' => array('type' => 'string', 'default' => '*nodefault*'),
        'vsys' => array('type' => 'string', 'default' => '*NULL*')
    ),
);


//                                                 //
//               Log based Actions                 //
//                                                 //
RuleCallContext::$supportedActions[] = array(
    'name' => 'logStart-Enable',
    'section' => 'log',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "   * SKIPPED : this is not a security rule";
            return;
        }

        if( $context->isAPI )
            $rule->API_setLogStart(TRUE);
        else
            $rule->setLogStart(TRUE);
    },
    'help' => 'disables "log at start" in a security rule'
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'logStart-Disable',
    'section' => 'log',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setLogStart(FALSE);
        else
            $rule->setLogStart(FALSE);
    },
    'help' => 'enables "log at start" in a security rule'
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'logStart-Enable-FastAPI',
    'section' => 'log',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( !$context->isAPI )
            derr("only supported in API mode!");

        if( $rule->setLogStart(TRUE) )
        {
            print $context->padding . " - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<log-start>yes</log-start>');
        }
    },
    'GlobalFinishFunction' => function (RuleCallContext $context) {
        $context->doBundled_API_Call();
    },
    'help' => "enables 'log at start' in a security rule.\n'FastAPI' allows API commands to be sent all at once instead of a single call per rule, allowing much faster execution time."
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'logStart-Disable-FastAPI',
    'section' => 'log',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( !$context->isAPI )
            derr("only supported in API mode!");

        if( $rule->setLogStart(FALSE) )
        {
            print $context->padding . " - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<log-start>no</log-start>');
        }
    },
    'GlobalFinishFunction' => function (RuleCallContext $context) {
        $context->doBundled_API_Call();
    },
    'help' => "disables 'log at start' in a security rule.\n'FastAPI' allows API commands to be sent all at once instead of a single call per rule, allowing much faster execution time."
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'logEnd-Enable',
    'section' => 'log',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setLogEnd(TRUE);
        else
            $rule->setLogEnd(TRUE);
    },
    'help' => "enables 'log at end' in a security rule."
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'logEnd-Disable',
    'section' => 'log',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setLogEnd(FALSE);
        else
            $rule->setLogEnd(FALSE);
    },
    'help' => "disables 'log at end' in a security rule."
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'logend-Disable-FastAPI',
    'section' => 'log',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( !$context->isAPI )
            derr("only supported in API mode!");

        if( $rule->setLogEnd(FALSE) )
        {
            print $context->padding . " - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<log-end>no</log-end>');
        }
    },
    'GlobalFinishFunction' => function (RuleCallContext $context) {
        $context->doBundled_API_Call();
    },
    'help' => "disables 'log at end' in a security rule.\n'FastAPI' allows API commands to be sent all at once instead of a single call per rule, allowing much faster execution time."
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'logend-Enable-FastAPI',
    'section' => 'log',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( !$context->isAPI )
            derr("only supported in API mode!");

        if( $rule->setLogEnd(TRUE) )
        {
            print $context->padding . " - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<log-end>yes</log-end>');
        }
    },
    'GlobalFinishFunction' => function (RuleCallContext $context) {
        $context->doBundled_API_Call();
    },
    'help' => "enables 'log at end' in a security rule.\n'FastAPI' allows API commands to be sent all at once instead of a single call per rule, allowing much faster execution time."
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'logSetting-set',
    'section' => 'log',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setLogSetting($context->arguments['profName']);
        else
            $rule->setLogSetting($context->arguments['profName']);
    },
    'args' => array('profName' => array('type' => 'string', 'default' => '*nodefault*')),
    'help' => "Sets log setting/forwarding profile of a Security rule to the value specified."
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'logSetting-set-FastAPI',
    'section' => 'log',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "   * SKIPPED : this is not a security rule\n";
            return;
        }

        if( !$context->isAPI )
            derr("only supported in API mode!");

        if( $rule->setLogSetting($context->arguments['profName']) )
        {
            print $context->padding . " - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<log-setting>' . $context->arguments['profName'] . '</log-setting>');
        }
    },
    'GlobalFinishFunction' => function (RuleCallContext $context) {
        $context->doBundled_API_Call();
    },
    'args' => array('profName' => array('type' => 'string', 'default' => '*nodefault*')),
    'help' => "Sets log setting/forwarding profile of a Security rule to the value specified."
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'logSetting-disable',
    'section' => 'log',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "   * SKIPPED : this is not a security rule\n";
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
RuleCallContext::$supportedActions[] = array(
    'name' => 'securityProfile-Group-Set',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "  - SKIPPED : this is not a Security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setSecurityProfileGroup($context->arguments['profName']);
        else
            $rule->setSecurityProfileGroup($context->arguments['profName']);
    },
    'args' => array('profName' => array('type' => 'string', 'default' => '*nodefault*'))
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'securityProfile-Group-Set-Force',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "  - SKIPPED : this is not a Security rule\n";
            return;
        }

        if( $context->isAPI )
        {
            mwarning( "not supported yet" );
        }
        else
        {
            $objectFind = $rule->owner->owner->securityProfileGroupStore->findOrCreate( $context->arguments['profName'] );
        }


        if( $context->isAPI )
            $rule->API_setSecurityProfileGroup($context->arguments['profName']);
        else
            $rule->setSecurityProfileGroup($context->arguments['profName']);
    },
    'args' => array('profName' => array('type' => 'string', 'default' => '*nodefault*'))
);
RuleCallContext::$supportedActions[] = Array(
    'name' => 'securityProfile-replace-by-Group',
    'MainFunction' => function(RuleCallContext $context)
    {
        /*
         * @var securityRule $rule
         */
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
            return false;

        if( $rule->securityProfileIsBlank() || $rule->securityProfileType() == "group" )
            return false;


        $profiles = $rule->securityProfiles();
        $profiles_obj = $rule->securityProfiles_obj();


        $rule_secprof = $rule->owner->owner->securityProfileGroupStore->calculateHash( $profiles );

        $secprofgroup = $rule->owner->owner->securityProfileGroupStore->findByHash( $rule_secprof );
        if( $secprofgroup == null )
        {
            #print PH::boldText(  "SecurityProfileGroup not found\n" );
            $secprofgroup = $rule->owner->owner->securityProfileGroupStore->createSecurityProfileGroup_based_Profile( $profiles );
        }

        if( $secprofgroup != null )
        {
            print "        * set SecurityProfileGroup: ".PH::boldText( $secprofgroup->name() )."\n";

            $rule->setSecurityProfileGroup( $secprofgroup->name() );
            if( $context->isAPI )
                $rule->API_sync();
        }
    }
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'securityProfile-Profile-Set',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        $type = $context->arguments['type'];
        $profName = $context->arguments['profName'];

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "  - SKIPPED : this is not a Security rule\n";
            return;
        }

        $ret = TRUE;

        if( $type == 'virus' )
            $ret = $rule->setSecProf_AV($profName);
        elseif( $type == 'vulnerability' )
            $ret = $rule->setSecProf_Vuln($profName);
        elseif( $type == 'url-filtering' )
            $ret = $rule->setSecProf_URL($profName);
        elseif( $type == 'data-filtering' )
            $ret = $rule->setSecProf_DataFilt($profName);
        elseif( $type == 'file-blocking' )
            $ret = $rule->setSecProf_FileBlock($profName);
        elseif( $type == 'spyware' )
            $ret = $rule->setSecProf_Spyware($profName);
        elseif( $type == 'wildfire' )
            $ret = $rule->setSecProf_Wildfire($profName);
        else
            derr("unsupported profile type '{$type}'");

        if( !$ret )
        {
            echo $context->padding . " * SKIPPED : no change detected\n";
            return;
        }


        if( $context->isAPI )
        {
            $xpath = $rule->getXPath() . '/profile-setting';
            $con = findConnectorOrDie($rule);
            $con->sendEditRequest($xpath, DH::dom_to_xml($rule->secprofroot, -1, FALSE));
        }
        else
            $rule->rewriteSecProfXML();

    },
    'args' => array('type' => array('type' => 'string', 'default' => '*nodefault*',
        'choices' => array('virus', 'vulnerability', 'url-filtering', 'data-filtering', 'file-blocking', 'spyware', 'wildfire')),
        'profName' => array('type' => 'string', 'default' => '*nodefault*'))
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'securityProfile-Remove',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $type = $context->arguments['type'];

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "  - SKIPPED : this is not a Security rule\n";
            return;
        }

        $ret = TRUE;
        $profName = "null";

        if( $type == "any" )
        {
            if( $context->isAPI )
                $rule->API_removeSecurityProfile();
            else
                $rule->removeSecurityProfile();
        }
        elseif( $type == 'virus' )
            $ret = $rule->setSecProf_AV($profName);
        elseif( $type == 'vulnerability' )
            $ret = $rule->setSecProf_Vuln($profName);
        elseif( $type == 'url-filtering' )
            $ret = $rule->setSecProf_URL($profName);
        elseif( $type == 'data-filtering' )
            $ret = $rule->setSecProf_DataFilt($profName);
        elseif( $type == 'file-blocking' )
            $ret = $rule->setSecProf_FileBlock($profName);
        elseif( $type == 'spyware' )
            $ret = $rule->setSecProf_Spyware($profName);
        elseif( $type == 'wildfire' )
            $ret = $rule->setSecProf_Wildfire($profName);
        else
            derr("unsupported profile type '{$type}'");

        if( $type != "any" )
        {
            if( !$ret )
            {
                echo $context->padding . " * SKIPPED : no change detected\n";
                return;
            }


            if( $context->isAPI )
            {
                $xpath = $rule->getXPath() . '/profile-setting';
                $con = findConnectorOrDie($rule);
                $con->sendEditRequest($xpath, DH::dom_to_xml($rule->secprofroot, -1, FALSE));
            }
            else
                $rule->rewriteSecProfXML();
        }

    },
    'args' => array('type' => array('type' => 'string', 'default' => 'any',
        'choices' => array('any', 'virus', 'vulnerability', 'url-filtering', 'data-filtering', 'file-blocking', 'spyware', 'wildfire'))
    )
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'securityProfile-Remove-FastAPI',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "  - SKIPPED : this is not a Security rule\n";
            return;
        }

        if( !$context->isAPI )
            derr("only supported in API mode!");

        if( $rule->removeSecurityProfile() )
        {
            print $context->padding . " - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<profile-setting><profiles/></profile-setting>');
        }
    },
    'GlobalFinishFunction' => function (RuleCallContext $context) {
        $context->doBundled_API_Call();
    },
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'securityProfile-Group-Set-FastAPI',
    'section' => 'log',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . " - SKIPPED : this is not a Security rule\n";
            return;
        }

        if( !$context->isAPI )
            derr("only supported in API mode!");

        if( $rule->setSecurityProfileGroup($context->arguments['profName']) )
        {
            print $context->padding . " - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<profile-setting><group><member>' . $context->arguments['profName'] . '</member></group></profile-setting>');
        }
    },
    'GlobalFinishFunction' => function (RuleCallContext $context) {
        $context->doBundled_API_Call();
    },
    'args' => array('profName' => array('type' => 'string', 'default' => '*nodefault*'))
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'description-Append',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $description = $rule->description();


        $textToAppend = "";
        if( $description != "" )
            $textToAppend = " ";
        if( $context->arguments['newline'] == 'yes' )
            $textToAppend = "\n";
        $textToAppend .= $context->rawArguments['text'];

        if( $context->object->owner->owner->version < 71 )
            $max_length = 253;
        else
            $max_length = 1020;

        if( strlen($description) + strlen($textToAppend) > $max_length )
        {
            echo $context->padding . " - SKIPPED : resulting description is too long\n";
            return;
        }

        echo $context->padding . " - new description will be: '{$description}{$textToAppend}' ... ";

        if( $context->isAPI )
            $rule->API_setDescription($description . $textToAppend);
        else
            $rule->setDescription($description . $textToAppend);

        echo "OK";
    },
    'args' => array('text' => array('type' => 'string', 'default' => '*nodefault*'), 'newline' => array('type' => 'bool', 'default' => 'no'))
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'description-Prepend',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $description = $rule->description();


        $textToPrepend = $context->rawArguments['text'];
        if( $context->arguments['newline'] == 'yes' )
            $textToPrepend .= "\n";

        if( $context->object->owner->owner->version < 71 )
            $max_length = 253;
        else
            $max_length = 1020;

        if( strlen($description) + strlen($textToPrepend) > $max_length )
        {
            echo $context->padding . " - SKIPPED : resulting description is too long\n";
            return;
        }

        echo $context->padding . " - new description will be: '{$textToPrepend}{$description}' ... ";

        if( $context->isAPI )
            $rule->API_setDescription($textToPrepend . $description);
        else
            $rule->setDescription($textToPrepend . $description);

        echo "OK";
    },
    'args' => array('text' => array('type' => 'string', 'default' => '*nodefault*'), 'newline' => array('type' => 'bool', 'default' => 'no'))
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'description-Replace-Character',
    'MainFunction' => function (RuleCallContext $context) {

        $object = $context->object;

        $characterToreplace = $context->arguments['search'];
        $characterForreplace = $context->arguments['replace'];

        $description = $object->description();

        $newDescription = str_replace($characterToreplace, $characterForreplace, $description);
        //todo add regex replacement 20210305
        //$desc = preg_replace('/appRID#[0-9]+/', '', $rule->description());

        if( $description == $newDescription )
        {
            echo $context->padding . " *** SKIPPED : new and old description are the same\n";
            return;
        }

        echo $context->padding . " - new description will be '{$newDescription}'\n";
        if( $context->isAPI )
            $object->API_setDescription($newDescription);
        else
            $object->setDescription($newDescription);
        echo "OK!\n";

    },
    'args' => array(
        'search' => array('type' => 'string', 'default' => '*nodefault*'),
        'replace' => array('type' => 'string', 'default' => '*nodefault*')
    ),
    'help' => ''
);

//                                                   //
//                Other property Based Actions       //
//                                                   //
RuleCallContext::$supportedActions[] = array(
    'name' => 'enabled-Set',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        if( $context->isAPI )
            $rule->API_setEnabled($context->arguments['trueOrFalse']);
        else
            $rule->setEnabled($context->arguments['trueOrFalse']);
    },
    'args' => array('trueOrFalse' => array('type' => 'bool', 'default' => 'yes'))
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'enabled-Set-FastAPI',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        if( !$context->isAPI )
            derr('you cannot call this action without API mode');

        if( $rule->setEnabled($context->arguments['trueOrFalse']) )
        {
            print $context->padding . " - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<disabled>' . boolYesNo(!$context->arguments['trueOrFalse']) . '</disabled>');
        }
    },
    'GlobalFinishFunction' => function (RuleCallContext $context) {
        $context->doBundled_API_Call();
    },
    'args' => array('trueOrFalse' => array('type' => 'bool', 'default' => 'yes'))
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'disabled-Set',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        if( $context->isAPI )
            $rule->API_setDisabled($context->arguments['trueOrFalse']);
        else
            $rule->setDisabled($context->arguments['trueOrFalse']);
    },
    'args' => array('trueOrFalse' => array('type' => 'bool', 'default' => 'yes'))
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'disabled-Set-FastAPI',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        if( !$context->isAPI )
            derr('you cannot call this action without API mode');

        if( $rule->setDisabled($context->arguments['trueOrFalse']) )
        {
            print $context->padding . " - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<disabled>' . boolYesNo($context->arguments['trueOrFalse']) . '</disabled>');
        }
    },
    'GlobalFinishFunction' => function (RuleCallContext $context) {
        $context->doBundled_API_Call();
    },
    'args' => array('trueOrFalse' => array('type' => 'bool', 'default' => 'yes'))
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'delete',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        if( $context->isAPI )
            $rule->owner->API_remove($rule);
        else
            $rule->owner->remove($rule);
    }
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'dsri-Set',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . " * SKIPPED it's not a security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setDsri($context->arguments['trueOrFalse']);
        else
            $rule->setDsri($context->arguments['trueOrFalse']);
    },
    'args' => array('trueOrFalse' => array('type' => 'bool', 'default' => 'no'))
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'dsri-Set-FastAPI',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . " * SKIPPED it's not a security rule\n";
            return;
        }

        if( !$context->isAPI )
            derr('you cannot call this action without API mode');

        if( $rule->setDsri($context->arguments['trueOrFalse']) )
        {
            print $context->padding . " - QUEUED for bundled API call\n";
            $context->addRuleToMergedApiChange('<option><disable-server-response-inspection>' . boolYesNo($context->arguments['trueOrFalse']) . '</disable-server-response-inspection></option>');
        }
    },
    'GlobalFinishFunction' => function (RuleCallContext $context) {
        $context->doBundled_API_Call();
    },
    'args' => array('trueOrFalse' => array('type' => 'bool', 'default' => 'no'))
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'biDirNat-Split',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isNatRule() )
        {
            print $context->padding . " * SKIPPED it's not a NAT rule\n";
            return;
        }
        /** @var NatRule $rule */

        if( !$rule->isBiDirectional() )
        {
            print $context->padding . " * SKIPPED because NAT rule is not bi-directional\n";
            return;
        }


        $newName = $rule->owner->findAvailableName($rule->name(), $context->arguments['suffix']);

        $rule->setBiDirectional(FALSE);

        // Now creating the reverse NAT rule
        $newRule = $rule->owner->newNatRule($newName);
        $rule->owner->moveRuleAfter($newRule, $rule);
        $newRule->to->copy($rule->to);
        $newRule->destination->copy($rule->snathosts);
        $newRule->setService($rule->service);
        $test = $rule->source->members();
        $newRule->setDNAT(reset($test));
        $newRule->tags->copy($rule->tags);
        $newRule->setDestinationInterface($rule->destinationInterface());

        if( $context->isAPI )
        {
            $newRule->API_sync();
            $rule->API_sync();
            $newRule->owner->API_moveRuleAfter($newRule, $rule);
        }

    },
    'args' => array('suffix' => array('type' => 'string', 'default' => '-DST'),)
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'DNat-set',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isNatRule() )
        {
            print $context->padding . " * SKIPPED it's not a NAT rule\n";
            return;
        }
        /** @var NatRule $rule */

        if( $rule->isBiDirectional() )
        {
            //Todo: validation if something is needed
            //print $context->padding . " * SKIPPED because NAT rule is bi-directional\n";
        }

        $dnattype = $context->arguments['DNATtype'];
        $port = $context->arguments['servicePort'];

        $objectFind = $rule->source->parentCentralStore->find($context->arguments['objName']);
        if( $objectFind === null )
            derr("address-type object named '{$context->arguments['objName']}' not found");

        if( $context->isAPI )
            $rule->API_setDNAT( $objectFind, null, $dnattype  );
        else
            $rule->setDNAT( $objectFind, null, $dnattype );

    },
    'args' => array(
        'DNATtype' => array(
            'type' => 'string',
            'default' => 'static',
            'help' =>
                "The following DNAT-type are possible:\n" .
                "  - static\n" .
                "  - dynamic\n" .
                "  - none\n"
        ),
        'objName' => array('type' => 'string', 'default' => '*nodefault*'),
        'servicePort' => array('type' => 'string', 'default' => '*nodefault*')
    )
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'name-Prepend',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        $newName = $context->rawArguments['text'] . $rule->name();

        if( strlen($newName) > 31 )
        {
            if( $context->object->owner->owner->version > 80 && strlen($newName) <= 63 && $context->arguments['accept63characters'] )
            {
                //do nothing
            }
            else
            {
                print $context->padding . " * SKIPPED because new name '{$newName}' is too long\n";
                return;
            }
        }

        if( !$rule->owner->isRuleNameAvailable($newName) )
        {
            print $context->padding . " * SKIPPED because name '{$newName}' is not available\n";
            return;
        }

        echo $context->padding . " - new name will be '{$newName}'\n";
        
        if( $context->isAPI )
        {
            $rule->API_setName($newName);
        }
        else
        {
            $rule->setName($newName);
        }
    },
    'GlobalFinishFunction' => function (RuleCallContext $context) {
        if( $context->object->owner->owner->version > 80 && !$context->object->owner->owner->isFirewall() && $context->arguments['accept63characters'] )
        {
            print PH::boldText("Panorama PAN-OS version 8.1 allow rule name >31 and <63 characters.\n" .
                "Please be aware that there is no validation available if DeviceGroup is connected to a firewall running PAN-OS <8.1.\n" .
                "If DG connected Firewall is PAN-OS version <8.1, Panorama push to device will fail with an error message.\n");
        }
    },
    'args' => array('text' => array('type' => 'string', 'default' => '*nodefault*'),
        'accept63characters' => array(
            'type' => 'bool',
            'default' => 'false',
            'help' =>
                "This bool is used to allow longer rule name for PAN-OS starting with version 8.1."
        )
    )
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'name-Append',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        $newName = $rule->name() . $context->rawArguments['text'];

        if( strlen($newName) > 31 )
        {
            if( $context->object->owner->owner->version > 80 && strlen($newName) <= 63 && $context->arguments['accept63characters'] )
            {
                //do nothing
            }
            else
            {
                print $context->padding . " * SKIPPED because new name '{$newName}' is too long\n";
                return;
            }
        }

        if( !$rule->owner->isRuleNameAvailable($newName) )
        {
            print $context->padding . " * SKIPPED because name '{$newName}' is not available\n";
            return;
        }

        echo $context->padding . " - new name will be '{$newName}'\n";

        if( $context->isAPI )
        {
            $rule->API_setName($newName);
        }
        else
        {
            $rule->setName($newName);
        }
    },
    'GlobalFinishFunction' => function (RuleCallContext $context) {
        if( $context->object->owner->owner->version > 80 && !$context->object->owner->owner->isFirewall() && $context->arguments['accept63characters'] )
        {
            print PH::boldText("\nPanorama PAN-OS version 8.1 allow rule name >31 and <63 characters.\n" .
                "Please be aware that there is no validation available if DeviceGroup is connected to a firewall running PAN-OS <8.1.\n" .
                "If DG connected Firewall is PAN-OS version <8.1, Panorama push to device will fail with an error message.\n");
        }
    },
    'args' => array('text' => array('type' => 'string', 'default' => '*nodefault*'),
        'accept63characters' => array(
            'type' => 'bool',
            'default' => 'false',
            'help' =>
                "This bool is used to allow longer rule name for PAN-OS starting with version 8.1."
        )
    )
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'name-removePrefix',
    'MainFunction' => function (RuleCallContext $context) {
        $object = $context->object;
        $prefix = $context->rawArguments['prefix'];

        if( strpos($object->name(), $prefix) !== 0 )
        {
            echo $context->padding . " *** SKIPPED : prefix not found\n";
            return;
        }
        $newName = substr($object->name(), strlen($prefix));

        if( !preg_match("/^[a-zA-Z0-9]/", $newName[0]) )
        {
            echo $context->padding . " *** SKIPPED : object name contains not allowed character at the beginning\n";
            return;
        }

        echo $context->padding . " - new name will be '{$newName}'\n";

        $rootObject = PH::findRootObjectOrDie($object->owner->owner);

        if( $object->owner->find($newName) !== null )
        {
            echo $context->padding . " *** SKIPPED : an object with same name already exists\n";
            return;
        }
        if( $context->isAPI )
            $object->API_setName($newName);
        else
            $object->setName($newName);
    },
    'args' => array('prefix' => array('type' => 'string', 'default' => '*nodefault*')
    ),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'name-removeSuffix',
    'MainFunction' => function (RuleCallContext $context) {
        $object = $context->object;
        $suffix = $context->rawArguments['suffix'];
        $suffixStartIndex = strlen($object->name()) - strlen($suffix);

        if( substr($object->name(), $suffixStartIndex, strlen($object->name())) != $suffix )
        {
            echo $context->padding . " *** SKIPPED : suffix not found\n";
            return;
        }
        $newName = substr($object->name(), 0, $suffixStartIndex);

        echo $context->padding . " - new name will be '{$newName}'\n";

        $rootObject = PH::findRootObjectOrDie($object->owner->owner);

        if( $object->owner->find($newName) !== null )
        {
            echo $context->padding . " *** SKIPPED : an object with same name already exists\n";
            return;
        }
        if( $context->isAPI )
            $object->API_setName($newName);
        else
            $object->setName($newName);
    },
    'args' => array('suffix' => array('type' => 'string', 'default' => '*nodefault*')
    ),
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'name-Rename',
    'GlobalInitFunction' => function (RuleCallContext $context) {
        $context->numCount = 0;
    },
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        $newName = $context->arguments['stringFormula'];
        $context->numCount++;

        if( strpos($newName, '$$sequential.number$$') !== FALSE )
            $newName = str_replace('$$sequential.number$$', $context->numCount, $newName);

        if( strpos($newName, '$$current.name$$') !== FALSE )
            $newName = str_replace('$$current.name$$', $rule->name(), $newName);

        if( strlen($newName) > 31 )
        {
            if( $context->object->owner->owner->version > 80 && strlen($newName) <= 63 && $context->arguments['accept63characters'] )
            {
                //do nothing
            }
            else
            {
                print $context->padding . " * SKIPPED because new name '{$newName}' is too long\n";
                return;
            }
        }

        if( !$rule->owner->isRuleNameAvailable($newName) )
        {
            print $context->padding . " * SKIPPED because name '{$newName}' is not available\n";
            return;
        }

        echo $context->padding . " - new name will be '{$newName}'\n";

        if( $context->isAPI )
        {
            $rule->API_setName($newName);
        }
        else
        {
            $rule->setName($newName);
        }
    },
    'GlobalFinishFunction' => function (RuleCallContext $context) {
        if( $context->object->owner->owner->version > 80 && !$context->object->owner->owner->isFirewall() && $context->arguments['accept63characters'] )
        {
            print PH::boldText("Panorama PAN-OS version 8.1 allow rule name >31 and <63 characters.\n" .
                "Please be aware that there is no validation available if DeviceGroup is connected to a firewall running PAN-OS <8.1.\n" .
                "If DG connected Firewall is PAN-OS version <8.1, Panorama push to device will fail with an error message.\n");
        }
    },

    'args' => array('stringFormula' => array(
        'type' => 'string',
        'default' => '*nodefault*',
        'help' =>
            "This string is used to compose a name. You can use the following aliases :\n" .
            "  - \$\$current.name\$\$ : current name of the object\n" .
            "  - \$\$sequential.number\$\$ : sequential number - starting with 1\n"
    ),
        'accept63characters' => array(
            'type' => 'bool',
            'default' => 'false',
            'help' =>
                "This bool is used to allow longer rule name for PAN-OS starting with version 8.1."
        )
    ),
    'help' => ''
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'name-Replace-Character',
    'GlobalInitFunction' => function (RuleCallContext $context) {
        $context->numCount = 0;
    },
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        $characterToreplace = $context->arguments['search'];
        $characterForreplace = $context->arguments['replace'];


        $newName = str_replace($characterToreplace, $characterForreplace, $rule->name());


        if( strlen($newName) > 31 && $context->object->owner->owner->version < 81 )
        {
            print $context->padding . " * SKIPPED because new name '{$newName}' is too long\n";
            return;
        }

        if( !$rule->owner->isRuleNameAvailable($newName) )
        {
            print $context->padding . " * SKIPPED because name '{$newName}' is not available\n";
            return;
        }

        echo $context->padding . " - new name will be '{$newName}'\n";

        if( $context->isAPI )
        {
            $rule->API_setName($newName);
        }
        else
        {
            $rule->setName($newName);
        }
    },

    'args' => array('search' => array(
        'type' => 'string',
        'default' => '*nodefault*'),
        'replace' => array(
            'type' => 'string',
            'default' => '*nodefault*')
    ),
    'help' => ''
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'ruleType-Change',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $newType = $context->arguments['text'];

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . " * SKIPPED it's not a security rule\n";
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
    'args' => array('text' => array('type' => 'string', 'default' => '*nodefault*'),)
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'display',
    'MainFunction' => function (RuleCallContext $context) {
        $context->object->display(7);
    }
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'invertPreAndPost',
    'MainFunction' => function (RuleCallContext $context) {
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


RuleCallContext::$supportedActions[] = array(
    'name' => 'copy',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $args = &$context->arguments;
        $location = $args['location'];
        $pan = PH::findRootObjectOrDie($rule);;

        if( $args['preORpost'] == "post" )
            $preORpost = TRUE;
        else
            $preORpost = FALSE;


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
    'args' => array('location' => array('type' => 'string', 'default' => '*nodefault*'),
        'preORpost' => array('type' => 'string', 'default' => 'pre', 'choices' => array('pre', 'post')))
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'move',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $args = &$context->arguments;
        $location = $args['location'];
        $pan = PH::findRootObjectOrDie($rule);;

        if( $args['preORpost'] == "post" )
            $moveToPost = TRUE;
        else
            $moveToPost = FALSE;


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
            if( $ruleStore === $rule->owner )
            {
                if( $moveToPost )
                    $ruleStore->API_moveRuleToPostRulebase($rule);
                else
                    $ruleStore->API_moveRuleToPreRulebase($rule);

            }
            else
            {
                $ruleStore->API_cloneRule($rule, null, $moveToPost);
                $rule->owner->API_remove($rule);
            }
        }
        else
        {
            if( $ruleStore === $rule->owner )
            {
                if( $moveToPost )
                    $ruleStore->moveRuleToPostRulebase($rule);
                else
                    $ruleStore->moveRuleToPreRulebase($rule);

            }
            else
            {
                $ruleStore->cloneRule($rule, null, $moveToPost);
                $rule->owner->remove($rule);
            }
        }

    },
    'args' => array('location' => array('type' => 'string', 'default' => '*nodefault*'),
        'preORpost' => array('type' => 'string', 'default' => 'pre', 'choices' => array('pre', 'post')))
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'position-Move-to-Top',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $ruleStore = $rule->owner;

        $serial = spl_object_hash($ruleStore);
        $firstTimeHere = FALSE;

        if( $context->baseObject->isPanorama() )
        {
            if( $rule->isPreRule() )
            {
                if( isset($context->preCache[$serial]) )
                    $referenceRule = $context->preCache[$serial];
                else
                {
                    $referenceRule = $ruleStore->getRuleOnTop($rule->isPreRule());
                    $firstTimeHere = TRUE;
                }

                $context->preCache[$serial] = $rule;
            }
            else
            {
                if( isset($context->postCache[$serial]) )
                    $referenceRule = $context->postCache[$serial];
                else
                {
                    $referenceRule = $ruleStore->getRuleOnTop($rule->isPreRule());
                    $firstTimeHere = TRUE;
                }

                $context->postCache[$serial] = $rule;
            }
        }
        else
        {
            if( isset($context->cache[$serial]) )
                $referenceRule = $context->cache[$serial];
            else
            {
                $referenceRule = $ruleStore->getRuleOnTop($rule->isPreRule());
                $firstTimeHere = TRUE;
            }

            $context->cache[$serial] = $rule;
        }


        if( $referenceRule === $rule )
        {
            echo $context->padding . " * SKIPPED because it is already the first rule\n";
            return;
        }

        echo $context->padding . " - MOVING to top ... ";

        if( $firstTimeHere )
        {
            if( $context->isAPI )
                $ruleStore->API_moveRuleBefore($rule, $referenceRule);
            else
                $ruleStore->moveRuleBefore($rule, $referenceRule);
        }
        else
        {
            if( $context->isAPI )
                $ruleStore->API_moveRuleAfter($rule, $referenceRule);
            else
                $ruleStore->moveRuleAfter($rule, $referenceRule);
        }

        echo "OK!\n";
    },
    'GlobalInitFunction' => function (RuleCallContext $context) {
        $context->preCache = array();
        $context->postCache = array();
        $context->cache = array();
    },
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'position-Move-to-Bottom',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $ruleStore = $rule->owner;

        $referenceRule = $ruleStore->getRuleAtBottom($rule->isPreRule());

        if( $referenceRule === $rule )
        {
            echo $context->padding . " * SKIPPED because it is already the last rule\n";
            return;
        }

        echo $context->padding . " - MOVING to bottom ... ";
        if( $context->isAPI )
            $ruleStore->API_moveRuleAfter($rule, $referenceRule);
        else
            $ruleStore->moveRuleAfter($rule, $referenceRule);
        echo "OK!\n";
    },
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'position-Move-Before',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $ruleStore = $rule->owner;

        $referenceRule = $ruleStore->find($context->arguments['rulename']);

        if( $referenceRule === null )
        {
            echo $context->padding . " * SKIPPED reference rule '{$context->arguments['rulename']}' was not found\n";
            return;
        }

        if( $referenceRule->isPreRule() !== $rule->isPreRule() )
        {
            echo $context->padding . " * SKIPPED reference rule '{$context->arguments['rulename']}' and this rule should both be PRE or POST, not a mix\n";
            return;
        }

        if( $referenceRule === $rule )
        {
            echo $context->padding . " * SKIPPED because it is already the last rule\n";
            return;
        }

        echo $context->padding . " - MOVING to before '{$context->arguments['rulename']}' ... ";
        if( $context->isAPI )
            $ruleStore->API_moveRuleBefore($rule, $referenceRule);
        else
            $ruleStore->moveRuleBefore($rule, $referenceRule);
        echo "OK!\n";
    },
    'args' => array(
        'rulename' => array('type' => 'string', 'default' => '*nodefault*'),
    )
);


RuleCallContext::$supportedActions[] = array(
    'name' => 'position-Move-After',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $ruleStore = $rule->owner;
        $storeSerial = spl_object_hash($ruleStore);


        if( isset($context->cache[$storeSerial]) )
            $referenceRule = $context->cache[$storeSerial];
        else
        {
            $referenceRule = $ruleStore->find($context->arguments['rulename']);
            if( $referenceRule === null )
            {
                echo $context->padding . " * SKIPPED reference rule '{$context->arguments['rulename']}' was not found\n";
                return;
            }
            $context->cache[$storeSerial] = $referenceRule;
        }

        if( $referenceRule->isPreRule() !== $rule->isPreRule() )
        {
            echo $context->padding . " * SKIPPED reference rule '{$context->arguments['rulename']}' and this rule should both be PRE or POST, not a mix\n";
            return;
        }

        if( $context->arguments['rulename'] === $rule->name() )
        {
            echo $context->padding . " * SKIPPED this was the referenced rulename in argument\n";
            return;
        }

        $context->cache[$storeSerial] = $rule;

        echo $context->padding . " - MOVING to after '{$referenceRule->name()}' ... ";
        if( $context->isAPI )
            $ruleStore->API_moveRuleAfter($rule, $referenceRule);
        else
            $ruleStore->moveRuleAfter($rule, $referenceRule);
        echo "OK!\n";
    },
    'GlobalInitFunction' => function (RuleCallContext $context) {
        $context->cache = array();
    },
    'args' => array(
        'rulename' => array('type' => 'string', 'default' => '*nodefault*'),
    )
);


RuleCallContext::$supportedActions[] = array(
    'name' => 'exportToExcel',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $context->ruleList[] = $rule;
    },
    'GlobalInitFunction' => function (RuleCallContext $context) {
        $context->ruleList = array();
    },
    'GlobalFinishFunction' => function (RuleCallContext $context) {
        $rule = $context->object;
        $args = &$context->arguments;
        $filename = $args['filename'];

        $addResolvedAddressSummary = FALSE;
        $addResolvedServiceSummary = FALSE;

        $optionalFields = &$context->arguments['additionalFields'];

        if( isset($optionalFields['ResolveAddressSummary']) )
            $addResolvedAddressSummary = TRUE;
        if( isset($optionalFields['ResolveServiceSummary']) )
            $addResolvedServiceSummary = TRUE;

        $fields = array(
            'location' => 'location',
            'type' => 'type',
            'name' => 'name',
            'tag' => 'tags',
            'from' => 'from',
            'to' => 'to',
            'src_negated' => 'source_negated',
            'src' => 'source',
            'src_resolved_sum' => 'src_resolved_sum',
            'dst_negated' => 'destination_negated',
            'dst' => 'destination',
            'dst_resolved_sum' => 'dst_resolved_sum',
            'service' => 'service',
            'service_resolved_sum' => 'service_resolved_sum',
            'application' => 'application',
            'action' => 'action',
            'security' => 'security-profile',
            'disabled' => 'disabled',
            'src user' => 'src-user',
            'log start' => 'log_start',
            'log end' => 'log_end',
            'log prof' => 'log_profile',
            'log prof name' => 'log_profile_name',
            'snat type' => 'snat_type',
            'snat_address' => 'snat_address',
            'snat_address_resolved_sum' => 'snat_address_resolved_sum',
            'dnat_host' => 'dnat_host',
            'dnat_host_resolved_sum' => 'dnat_host_resolved_sum',
            'description' => 'description',
            'schedule' => 'schedule',
            'target' => 'target'
        );

        $lines = '';

        $count = 0;
        if( isset($context->ruleList) )
        {
            foreach( $context->ruleList as $rule )
            {
                $count++;

                /** @var SecurityRule|NatRule $rule */
                if( $count % 2 == 1 )
                    $lines .= "<tr>\n";
                else
                    $lines .= "<tr bgcolor=\"#DDDDDD\">";

                foreach( $fields as $fieldName => $fieldID )
                {
                    if( (($fieldName == 'src_resolved_sum' || $fieldName == 'dst_resolved_sum' ||
                                $fieldName == 'dnat_host_resolved_sum' || $fieldName == 'snat_address_resolved_sum') && !$addResolvedAddressSummary) ||
                        (($fieldName == 'service_resolved_sum') && !$addResolvedServiceSummary)
                    )
                        continue;
                    $lines .= $context->ruleFieldHtmlExport($rule, $fieldID);
                }


                $lines .= "</tr>\n";

            }
        }


        $tableHeaders = '';
        foreach( $fields as $fieldName => $value )
        {
            if( (($fieldName == 'src_resolved_sum' || $fieldName == 'dst_resolved_sum' ||
                        $fieldName == 'dnat_host_resolved_sum' || $fieldName == 'snat_address_resolved_sum') && !$addResolvedAddressSummary) ||
                (($fieldName == 'service_resolved_sum') && !$addResolvedServiceSummary)
            )
                continue;
            $tableHeaders .= "<th>{$fieldName}</th>\n";
        }

        $content = file_get_contents(dirname(__FILE__) . '/html-export-template.html');

        $content = str_replace('%TableHeaders%', $tableHeaders, $content);

        $content = str_replace('%lines%', $lines, $content);

        $jscontent = file_get_contents(dirname(__FILE__) . '/jquery-1.11.js');
        $jscontent .= "\n";
        $jscontent .= file_get_contents(dirname(__FILE__) . '/jquery.stickytableheaders.min.js');
        $jscontent .= "\n\$('table').stickyTableHeaders();\n";

        $content = str_replace('%JSCONTENT%', $jscontent, $content);

        file_put_contents($filename, $content);
    },
    'args' => array(
        'filename' => array('type' => 'string', 'default' => '*nodefault*'),
        'additionalFields' =>
            array('type' => 'pipeSeparatedList',
                'subtype' => 'string',
                'default' => '*NONE*',
                'choices' => array('ResolveAddressSummary', 'ResolveServiceSummary'),
                'help' => "pipe(|) separated list of additional field to include in the report. The following is available:\n" .
                    "  - ResolveAddressSummary : fields with address objects will be resolved to IP addressed and summarized in a new column)\n" .
                    "  - ResolveServiceSummary : fields with service objects will be resolved to their value and summarized in a new column)\n"
            )
    )
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'clone',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        $newName = $rule->owner->findAvailableName($rule->name(), $context->arguments['suffix']);

        print $context->padding . "   - cloned rule name will be '{$newName}'\n";

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
    'args' => array('before' => array('type' => 'bool', 'default' => 'yes'),
        'suffix' => array('type' => 'string', 'default' => '-cloned')
    )
);
RuleCallContext::$supportedActions[] = array(
    'name' => 'cloneForAppOverride',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . " - IGNORED because rule is not type 'Security'\n";
            return;
        }

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

        if( ($rule->services->isAny() || $rule->services->isApplicationDefault()) && $context->arguments['restrictToListOfServices'] != '*sameAsInRule*' )
        {
            $ports = '1-65535';
            $portMapping = ServiceDstPortMapping::mappingFromText($ports, TRUE);
            $udpPortMapping = ServiceDstPortMapping::mappingFromText($ports, FALSE);

            $portMapping->mergeWithMapping($udpPortMapping);
        }
        else
        {
            $portMapping = new ServiceDstPortMapping();

            if( $context->arguments['restrictToListOfServices'] == '*sameAsInRule*' )
            {
                $services = $rule->services->members();
            }
            elseif( $context->arguments['restrictToListOfServices'][0] == '@' )
            {
                $listOfServicesQueryName = substr($context->arguments['restrictToListOfServices'], 1);
                if( !isset($context->nestedQueries[$listOfServicesQueryName]) )
                {
                    derr("cannot find query filter called '$listOfServicesQueryName'");
                }

                $rQuery = new RQuery('service');
                $errorMessage = '';
                if( !$rQuery->parseFromString($context->nestedQueries[$listOfServicesQueryName], $errorMessage) )
                    derr("error while parsing query: {$context->nestedQueries[$listOfServicesQueryName]}");

                $services = array();

                foreach( $rule->services->membersExpanded() as $member )
                {
                    if( $rQuery->matchSingleObject($member) )
                    {
                        $services[] = $member;
                    }
                }
            }
            else
            {
                $listOfServices = explode('#', $context->arguments['restrictToListOfServices']);
                $listOfServices = array_flip($listOfServices);


                $services = array();

                foreach( $rule->services->membersExpanded() as $member )
                {
                    if( isset($listOfServices[$member->name()]) )
                    {
                        $services[] = $member;
                    }
                }
            }

            if( count($services) == 0 )
            {
                print $context->padding . " - IGNORED because NO MATCHING SERVICE FOUND\n";
                return;
            }
            $portMapping->mergeWithArrayOfServiceObjects($services);
        }

        $application = $rule->apps->parentCentralStore->findOrCreate($context->arguments['applicationName']);

        print $context->padding . " - Port mapping to import in AppOverride: " . $portMapping->mappingToText() . "\n";
        if( count($portMapping->tcpPortMap) > 0 )
        {
            $newName = $rule->owner->owner->appOverrideRules->findAvailableName($rule->name(), '');
            $newRule = $rule->owner->owner->appOverrideRules->newAppOverrideRule($newName, $rule->isPostRule());
            if( $rule->sourceIsNegated() )
                $newRule->setSourceIsNegated(TRUE);
            if( $rule->destinationIsNegated() )
                $newRule->setDestinationIsNegated(TRUE);

            $newRule->from->copy($rule->from);
            $newRule->to->copy($rule->to);
            $newRule->source->copy($rule->source);
            $newRule->destination->copy($rule->destination);
            $newRule->setTcp();
            $newRule->setPorts($portMapping->tcpMappingToText());
            $newRule->setApplication($application);

            if( $context->isAPI )
                $newRule->API_sync();
            print $context->padding . " - created TCP appOverride rule '{$newRule->name()}'\n";
        }
        if( count($portMapping->udpPortMap) > 0 )
        {
            $newName = $rule->owner->owner->appOverrideRules->findAvailableName($rule->name(), '');
            $newRule = $rule->owner->owner->appOverrideRules->newAppOverrideRule($newName, $rule->isPreRule());
            if( $rule->sourceIsNegated() )
                $newRule->setSourceIsNegated(TRUE);
            if( $rule->destinationIsNegated() )
                $newRule->setDestinationIsNegated(TRUE);

            $newRule->from->copy($rule->from);
            $newRule->to->copy($rule->to);
            $newRule->source->copy($rule->source);
            $newRule->destination->copy($rule->destination);
            $newRule->setUdp();
            $newRule->setPorts($portMapping->udpMappingToText());
            $newRule->setApplication($application);

            if( $context->isAPI )
                $newRule->API_sync();
            print $context->padding . " - created TCP appOverride rule '{$newRule->name()}'\n";
        }


    },
    'args' => array('applicationName' => array('type' => 'string', 'default' => '*nodefault*',
        'help' => 'specify the application to put in the resulting App-Override rule'),
        'restrictToListOfServices' => array('type' => 'string', 'default' => '*sameAsInRule*',
            'help' => "you can limit which services will be included in the AppOverride rule by providing a #-separated list or a subquery prefixed with a @:\n" .
                "  - svc1#svc2#svc3... : #-separated list\n" .
                "  - @subquery1 : script will look for subquery1 filter which you have to provide as an additional argument to the script (ie: 'subquery1=(name eq tcp-50-web)')"),
    ),
    'help' => "This action will take a Security rule and clone it as an App-Override rule. By default all services specified in the rule will also be in the AppOverride rule."
);

//                                                   //
//                User Based Actions     //
//
//Todo: implementation needed, someting got lost!!!
/*                                                   //
RuleCallContext::$supportedActions[] = Array(
    'name' => 'user-Set',
    'MainFunction' =>  function(RuleCallContext $context)
    {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding."  - SKIPPED : this is not a Security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setUser($context->arguments['userName']);
        else
            $rule->setUser($context->arguments['userName']);
    },
    'args' => Array( 'userName' => Array( 'type' => 'string', 'default' => '*nodefault*' ) )
);
*/

//                                                   //
//                HIP Based Actions     //
//                                                   //
RuleCallContext::$supportedActions[] = array(
    'name' => 'hip-Set',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "  - SKIPPED : this is not a Security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setHipProfil($context->arguments['HipProfile']);
        else
            $rule->setHipProfile($context->arguments['HipProfile']);
    },
    'args' => array('HipProfile' => array('type' => 'string', 'default' => '*nodefault*'))
);


//                                                   //
//                SCHEDULER Based Actions     //
//                                                   //
RuleCallContext::$supportedActions[] = array(
    'name' => 'schedule-Set',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "  - SKIPPED : this is not a Security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_setSchedule($context->arguments['Schedule']);
        else
            $rule->setSchedule($context->arguments['Schedule']);
    },
    'args' => array('Schedule' => array('type' => 'string', 'default' => '*nodefault*'))
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'schedule-Remove',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "  - SKIPPED : this is not a Security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_removeSchedule();
        else
            $rule->removeSchedule();
    },
);

// </editor-fold>


//                                                   //
//                QoSmarking Based Actions     //
//
//                                                   //

RuleCallContext::$supportedActions[] = array(
    'name' => 'qosMarking-Set',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "  - SKIPPED : this is not a Security rule\n";
            return;
        }

        if( $context->isAPI )
        {
            $rule->API_setQoSMarking($context->arguments['arg1'], $context->arguments['arg2']);
        }
        else
            $rule->setQoSMarking($context->arguments['arg1'], $context->arguments['arg2']);
    },
    'args' => array(
        'arg1' => array('type' => 'string', 'default' => '*nodefault*'),
        'arg2' => array('type' => 'string', 'default' => '*nodefault*')
    )
);

RuleCallContext::$supportedActions[] = array(
    'name' => 'qosMarking-Remove',
    'MainFunction' => function (RuleCallContext $context) {
        $rule = $context->object;

        if( !$rule->isSecurityRule() )
        {
            print $context->padding . "  - SKIPPED : this is not a Security rule\n";
            return;
        }

        if( $context->isAPI )
            $rule->API_removeQoSmarking();
        else
            $rule->removeQoSmarking();
    },
);


/************************************ */



