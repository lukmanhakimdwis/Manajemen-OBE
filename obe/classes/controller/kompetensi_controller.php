<?php
namespace local_obe\controller;
defined('MOODLE_INTERNAL') || die();
use local_obe\manager\kompetensi_manager;

class kompetensi_controller {

    public static function handle_request($aksi, $prodi_id) {
        global $DB;
        switch ($aksi) {
            case 'relasi':
                $kompetensi_id = required_param('id', PARAM_INT);
                $kompetensi_data = $DB->get_record('local_obe_kompetensi', ['id' => $kompetensi_id]);

                throw new \moodle_exception('Gunakan menu bobot relasi untuk mengatur hubungan kompetensi.');
                break;

            case 'sync':
                self::handle_sync($prodi_id);
                break;

            case 'update_ambang':
                $id = required_param('id', PARAM_INT);
                $nilai = required_param('ambang_batas', PARAM_FLOAT);
                self::handle_update_ambang($id, $nilai, $prodi_id);
                break;

            default:
                break;
        }
    }

    public static function handle_update_ambang($id, $nilai, $prodi_id) {
        \local_obe\access_guard::require_admin_or_same_prodi($prodi_id, 'local/obe:configprogramscale');
        require_sesskey();
        throw new \moodle_exception('Ambang kompeten dikelola melalui menu skala nilai, bukan per kompetensi.');
    }

    public static function handle_sync($prodi_id) {
        global $SESSION;
        require_sesskey();

        $prodi_id = \local_obe\access_guard::resolve_allowed_prodi($prodi_id);
        if ($prodi_id <= 0) {
            throw new \moodle_exception('Program studi wajib dipilih sebelum sinkronisasi.');
        }
        
        $tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        if (!kompetensi_manager::sync_competencies($prodi_id, $tahun_akademik)) {
            redirect(new \moodle_url('/local/obe/views/admin/dashboard.php', ['halaman'=>'kelola_framework','filter_prodi'=>$prodi_id]),
                'Kerangka kompetensi belum dihubungkan untuk prodi dan tahun ini. Hubungkan kerangka terlebih dahulu.', null, \core\output\notification::NOTIFY_WARNING);
        }
        global $DB;
        $synced=$DB->count_records('local_obe_kompetensi',['id_prodi'=>$prodi_id,'id_tahun_akademik'=>$tahun_akademik]);
        if (!$synced) {redirect(new \moodle_url('/local/obe/views/admin/dashboard.php',['halaman'=>'kelola_framework','filter_prodi'=>$prodi_id]),'Kerangka belum memiliki struktur kompetensi. Minta pengelola melengkapinya lalu sinkronkan kembali.',null,\core\output\notification::NOTIFY_WARNING);}
        \local_obe\access_guard::audit('sync', 'kompetensi', $prodi_id, ['tahun_akademik' => $tahun_akademik]);
        
        redirect(new \moodle_url('/local/obe/views/admin/dashboard.php', [
            'halaman' => 'manajemen_kompetensi', 
            'prodi'   => $prodi_id
        ]), 'Sinkronisasi berhasil!', 2);
    }

 
}