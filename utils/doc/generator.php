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
echo   "************ DOC GENERATOR  **************\n\n";

require_once("../../lib/panconfigurator.php");
require_once("../common/actions.php");

$dataFile = __DIR__.'/data.js';

$actionsData = Array();



foreach(RuleCallContext::$supportedActions as &$action)
{

    $record = Array( 'name' => $action['name'],'help' => null, 'args' => false );

    if( isset($action['help']) )
        $record['help'] = str_replace(  Array("\n"  , ' '),
            Array("<br>", '&nbsp'),
            $action['help']);

    if( isset($action['args']) && $action['args'] !== false )
    {
        $record['args'] = Array();
        foreach($action['args'] as $argName => $arg)
        {
            $tmpArr = $arg;
            $tmpArr['name'] = $argName;
            $record['args'][] = $tmpArr;
        }
    }

    $actionsData['rule'][] = $record;
}



$data = Array('actions' => &$actionsData);

$data = 'var data = '.json_encode($data, JSON_PRETTY_PRINT) .';';

file_put_contents($dataFile, $data);

echo "\nDOC GENERATED !!!\n\n";