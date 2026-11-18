<?php
namespace local_obe\ai;
defined('MOODLE_INTERNAL') || die();

/** Only an absent cmid mapping, never an access/configuration/database failure. */
final class mapping_missing extends \moodle_exception {
    public function __construct() {parent::__construct('aimappingmissing','local_obe');}
}
