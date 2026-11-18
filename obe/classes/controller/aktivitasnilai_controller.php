<?php
namespace local_obe\controller;
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\aktivitasnilai_manager;

class aktivitasnilai_controller {

    private static function validate_interview_focus(int $cmid,int $subid,int $courseid): void {
        global $DB;
        $cm=get_coursemodule_from_id('',$cmid,$courseid,false,MUST_EXIST);
        if ($cm->modname==='obeinterview') {
            $activity=$DB->get_record('obeinterview',['id'=>$cm->instance],'*',MUST_EXIST);
            \local_obe\ai\scope::focus((int)$cm->course,(int)$activity->focusid,$subid,\context_module::instance($cmid));
        }
    }
    /**
     * Menangani aksi Simpan Mapping Baru.
     */
    public static function eksekusi_simpan($courseid = 0) {
        $cmid        = required_param('cmid', PARAM_INT);
        $sub_cpmk_id = required_param('sub_cpmk_id', PARAM_INT);
        $bobot       = required_param('bobot', PARAM_FLOAT);

        self::validate_interview_focus($cmid,$sub_cpmk_id,$courseid);
        return aktivitasnilai_manager::insert($cmid, $sub_cpmk_id, $bobot, $courseid);
    }

    /**
     * Menangani aksi Update Mapping yang ada.
     */
    public static function eksekusi_update($courseid = 0) {
        $id          = required_param('id', PARAM_INT);
        $sub_cpmk_id = required_param('sub_cpmk_id', PARAM_INT);
        $bobot       = required_param('bobot', PARAM_FLOAT);

        global $DB;
        $current=$DB->get_record('local_obe_aktivitas_map',['id'=>$id],'*',MUST_EXIST);
        self::validate_interview_focus((int)$current->cmid,$sub_cpmk_id,$courseid);
        return aktivitasnilai_manager::update($id, $sub_cpmk_id, $bobot, $courseid);
    }

    /**
     * Menangani aksi Hapus Mapping.
     */
    public static function eksekusi_hapus($id) {
        global $DB;
        $map = $DB->get_record('local_obe_aktivitas_map', ['id' => $id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('', $map->cmid, 0, false, MUST_EXIST);
        \local_obe\access_guard::require_course_manage($cm->course);
        if ($id <= 0) {
            throw new \moodle_exception('invalidparameter', 'error');
        }
        return aktivitasnilai_manager::delete($id);
    }
}
