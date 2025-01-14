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

SecurityProfileGroupCallContext::$supportedActions['displayreferences'] = array(
    'name' => 'displayReferences',
    'MainFunction' => function (SecurityProfileCallContext $context) {
        $object = $context->object;

        $object->display_references(7);
    },
);



SecurityProfileGroupCallContext::$supportedActions[] = array(
    'name' => 'display',
    'MainFunction' => function (SecurityProfileGroupCallContext $context) {
        $object = $context->object;

        PH::print_stdout( $context->padding . "* " . get_class($object) . " '{$object->name()}' (".count($object->secprofiles )." members)" );
        foreach( $object->secprofiles as $key => $prof )
        {
            if( is_object( $prof ) )
                PH::print_stdout( "          - {$key}  '{$prof->name()}'" );
            else
            {
                //defautl prof is string not an object
                PH::print_stdout( "          - {$key}  '{$prof}'" );
            }
        }

        PH::print_stdout(  "" );
    },
);

SecurityProfileGroupCallContext::$supportedActions[] = array(
    'name' => 'securityProfile-Set',
    'MainFunction' => function (SecurityProfileGroupCallContext $context) {
        $secprofgroup = $context->object;

        $type = $context->arguments['type'];
        $profName = $context->arguments['profName'];


        $ret = TRUE;

        if( $type == 'virus' )
            $ret = $secprofgroup->setSecProf_AV($profName);
        elseif( $type == 'vulnerability' )
            $ret = $secprofgroup->setSecProf_Vuln($profName);
        elseif( $type == 'url-filtering' )
            $ret = $secprofgroup->setSecProf_URL($profName);
        elseif( $type == 'data-filtering' )
            $ret = $secprofgroup->setSecProf_DataFilt($profName);
        elseif( $type == 'file-blocking' )
            $ret = $secprofgroup->setSecProf_FileBlock($profName);
        elseif( $type == 'spyware' )
            $ret = $secprofgroup->setSecProf_Spyware($profName);
        elseif( $type == 'wildfire' )
            $ret = $secprofgroup->setSecProf_Wildfire($profName);
        else
            derr("unsupported profile type '{$type}'");

        if( !$ret )
        {
            echo $context->padding . " * SKIPPED : no change detected\n";
            return;
        }


        if( $context->isAPI )
        {
            $xpath = $secprofgroup->getXPath();
            $con = findConnectorOrDie($secprofgroup);
            $con->sendEditRequest($xpath, DH::dom_to_xml($secprofgroup->xmlroot, -1, FALSE));
        }
        else
            #$secprofgroup->rewriteSecProfXML();
            $secprofgroup->rewriteXML();

    },
    'args' => array('type' => array('type' => 'string', 'default' => '*nodefault*',
        'choices' => array('virus', 'vulnerability', 'url-filtering', 'data-filtering', 'file-blocking', 'spyware', 'wildfire')),
        'profName' => array('type' => 'string', 'default' => '*nodefault*'))
);
SecurityProfileGroupCallContext::$supportedActions[] = array(
    'name' => 'securityProfile-Remove',
    'MainFunction' => function (SecurityProfileGroupCallContext $context) {
        $secprofgroup = $context->object;
        $type = $context->arguments['type'];


        $ret = TRUE;
        $profName = "null";

        if( $type == "any" )
        {
            if( $context->isAPI )
                $secprofgroup->API_removeSecurityProfile();
            else
                $secprofgroup->removeSecurityProfile();
        }
        elseif( $type == 'virus' )
            $ret = $secprofgroup->setSecProf_AV($profName);
        elseif( $type == 'vulnerability' )
            $ret = $secprofgroup->setSecProf_Vuln($profName);
        elseif( $type == 'url-filtering' )
            $ret = $secprofgroup->setSecProf_URL($profName);
        elseif( $type == 'data-filtering' )
            $ret = $secprofgroup->setSecProf_DataFilt($profName);
        elseif( $type == 'file-blocking' )
            $ret = $secprofgroup->setSecProf_FileBlock($profName);
        elseif( $type == 'spyware' )
            $ret = $secprofgroup->setSecProf_Spyware($profName);
        elseif( $type == 'wildfire' )
            $ret = $secprofgroup->setSecProf_Wildfire($profName);
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
                $xpath = $secprofgroup->getXPath();
                $con = findConnectorOrDie($secprofgroup);
                $con->sendEditRequest($xpath, DH::dom_to_xml($secprofgroup->xmlroot, -1, FALSE));
            }
            else
                #$secprofgroup->rewriteSecProfXML();
                $secprofgroup->rewriteXML();
        }

    },
    'args' => array('type' => array('type' => 'string', 'default' => 'any',
        'choices' => array('any', 'virus', 'vulnerability', 'url-filtering', 'data-filtering', 'file-blocking', 'spyware', 'wildfire'))
    )
);
SecurityProfileGroupCallContext::$supportedActions[] = array(
    'name' => 'exportToExcel',
    'MainFunction' => function (SecurityProfileGroupCallContext $context) {
        $object = $context->object;
        $context->objectList[] = $object;
    },
    'GlobalInitFunction' => function (SecurityProfileGroupCallContext $context) {
        $context->objectList = array();
    },
    'GlobalFinishFunction' => function (SecurityProfileGroupCallContext $context) {
        $args = &$context->arguments;
        $filename = $args['filename'];

        $lines = '';
        $encloseFunction = function ($value, $nowrap = TRUE) {
            if( is_string($value) )
                $output = htmlspecialchars($value);
            elseif( is_array($value) )
            {
                $output = '';
                $first = TRUE;
                foreach( $value as $subValue )
                {
                    if( !$first )
                    {
                        $output .= '<br />';
                    }
                    else
                        $first = FALSE;

                    if( is_string($subValue) )
                        $output .= htmlspecialchars($subValue);
                    else
                        $output .= htmlspecialchars($subValue->name());
                }
            }
            elseif( is_object($value) )
                $output = htmlspecialchars($value->name());
            elseif( $value == null )
                $output = "---";
            else
            {
                derr('unsupported');
            }


            if( $nowrap )
                return '<td style="white-space: nowrap">' . $output . '</td>';

            return '<td>' . $output . '</td>';
        };


        $addWhereUsed = FALSE;
        $addUsedInLocation = FALSE;

        $optionalFields = &$context->arguments['additionalFields'];

        if( isset($optionalFields['WhereUsed']) )
            $addWhereUsed = TRUE;

        if( isset($optionalFields['UsedInLocation']) )
            $addUsedInLocation = TRUE;


        $headers = '<th>location</th><th>name</th><th>Antivirus</th><th>Anti-Spyware</th><th>Vulnerability</th><th>URL Filtering</th><th>File Blocking</th><th>Data Filtering</th><th>WildFire Analysis</th>';

        if( $addWhereUsed )
            $headers .= '<th>where used</th>';
        if( $addUsedInLocation )
            $headers .= '<th>location used</th>';

        $count = 0;
        if( isset($context->objectList) )
        {
            foreach( $context->objectList as $object )
            {
                $count++;

                /** @var Tag $object */
                if( $count % 2 == 1 )
                    $lines .= "<tr>\n";
                else
                    $lines .= "<tr bgcolor=\"#DDDDDD\">";

                $lines .= $encloseFunction(PH::getLocationString($object));

                $lines .= $encloseFunction($object->name());

                //private $secprof_array = array('virus', 'spyware', 'vulnerability', 'file-blocking', 'wildfire-analysis', 'url-filtering', 'data-filtering');

                $lines .= $encloseFunction($object->secprofiles['virus']);

                $lines .= $encloseFunction($object->secprofiles['spyware']);

                $lines .= $encloseFunction($object->secprofiles['vulnerability']);

                $lines .= $encloseFunction($object->secprofiles['url-filtering']);

                $lines .= $encloseFunction($object->secprofiles['file-blocking']);

                $lines .= $encloseFunction($object->secprofiles['data-filtering']);

                $lines .= $encloseFunction($object->secprofiles['wildfire-analysis']);

                if( $addWhereUsed )
                {
                    $refTextArray = array();
                    foreach( $object->getReferences() as $ref )
                        $refTextArray[] = $ref->_PANC_shortName();

                    $lines .= $encloseFunction($refTextArray);
                }
                if( $addUsedInLocation )
                {
                    $refTextArray = array();
                    foreach( $object->getReferences() as $ref )
                    {
                        $location = PH::getLocationString($object->owner);
                        $refTextArray[$location] = $location;
                    }

                    $lines .= $encloseFunction($refTextArray);
                }

                $lines .= "</tr>\n";
            }
        }

        $content = file_get_contents(dirname(__FILE__) . '/html-export-template.html');
        $content = str_replace('%TableHeaders%', $headers, $content);

        $content = str_replace('%lines%', $lines, $content);

        $jscontent = file_get_contents(dirname(__FILE__) . '/jquery-1.11.js');
        $jscontent .= "\n";
        $jscontent .= file_get_contents(dirname(__FILE__) . '/jquery.stickytableheaders.min.js');
        $jscontent .= "\n\$('table').stickyTableHeaders();\n";

        $content = str_replace('%JSCONTENT%', $jscontent, $content);

        file_put_contents($filename, $content);
    },
    'args' => array('filename' => array('type' => 'string', 'default' => '*nodefault*'),
        'additionalFields' =>
            array('type' => 'pipeSeparatedList',
                'subtype' => 'string',
                'default' => '*NONE*',
                'choices' => array('WhereUsed', 'UsedInLocation'),
                'help' =>
                    "pipe(|) separated list of additional field to include in the report. The following is available:\n" .
                    "  - WhereUsed : list places where object is used (rules, groups ...)\n" .
                    "  - UsedInLocation : list locations (vsys,dg,shared) where object is used\n")
    )
);