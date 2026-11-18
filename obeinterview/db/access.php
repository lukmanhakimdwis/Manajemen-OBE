<?php
defined('MOODLE_INTERNAL') || die();
$capabilities=[
 'mod/obeinterview:addinstance'=>['riskbitmask'=>RISK_XSS,'captype'=>'write','contextlevel'=>CONTEXT_COURSE,'archetypes'=>['editingteacher'=>CAP_ALLOW,'manager'=>CAP_ALLOW],'clonepermissionsfrom'=>'moodle/course:manageactivities'],
 'mod/obeinterview:view'=>['captype'=>'read','contextlevel'=>CONTEXT_MODULE,'archetypes'=>['student'=>CAP_ALLOW,'teacher'=>CAP_ALLOW,'editingteacher'=>CAP_ALLOW,'manager'=>CAP_ALLOW]],
 'mod/obeinterview:attempt'=>['captype'=>'write','contextlevel'=>CONTEXT_MODULE,'archetypes'=>['student'=>CAP_ALLOW]],
 'mod/obeinterview:grade'=>['captype'=>'write','contextlevel'=>CONTEXT_MODULE,'archetypes'=>['editingteacher'=>CAP_ALLOW,'manager'=>CAP_ALLOW]],
];
