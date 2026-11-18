<?php
require_once(__DIR__.'/../../config.php');
$courseid=required_param('id',PARAM_INT); $course=get_course($courseid); require_login($course);
redirect(new moodle_url('/course/view.php',['id'=>$courseid]));
