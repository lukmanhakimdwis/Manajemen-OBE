<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [

    'local/obe:manage' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    'local/obe:manageprogram' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    'local/obe:viewprogramreport' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    'local/obe:configprogramscale' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    'local/obe:managecourse' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    'local/obe:viewreport' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    'local/obe:viewownreport' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'student' => CAP_ALLOW,
        ],
    ],
    'local/obe:useai' => [
        'riskbitmask'=>RISK_PERSONAL, 'captype'=>'write','contextlevel'=>CONTEXT_COURSE,
        'archetypes'=>['editingteacher'=>CAP_ALLOW,'manager'=>CAP_ALLOW],
    ],
    'local/obe:approveai' => [
        'captype'=>'write','contextlevel'=>CONTEXT_COURSE,
        'archetypes'=>['editingteacher'=>CAP_ALLOW,'manager'=>CAP_ALLOW],
    ],
    'local/obe:ingestai' => [
        'captype'=>'write','contextlevel'=>CONTEXT_COURSE,'archetypes'=>[],
    ],
];
