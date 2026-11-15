<?php
namespace local_obe\controller;

defined('MOODLE_INTERNAL') || die();

use local_obe\manager\bobot_relasi_manager;
use local_obe\manager\prodi_manager;
use local_obe\manager\kompetensi_manager;

class bobot_relasi_controller {

    public static function handle_process($aksi, $courseid, $prodi) {
        global $SESSION;

        if ($aksi === 'simpan_bobot') {

            require_sesskey();

            $id_kompetensi  = required_param('id_kompetensi', PARAM_INT);
            $bobot          = optional_param('bobot', 0, PARAM_FLOAT);
            $tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

            // ============================================================
            // VALIDASI ANTI-IDOR UNTUK KAPRODI
            // ============================================================
            $prodi_aktif = prodi_manager::get_prodi_aktif_user();

            if ($prodi_aktif) {

                $kompetensi = kompetensi_manager::ambil_by_id($id_kompetensi);

                if (!$kompetensi) {
                    throw new \moodle_exception(
                        'invalidrecord',
                        'error'
                    );
                }

                if ((int)$kompetensi->id_prodi !== (int)$prodi_aktif->id) {
                    throw new \moodle_exception(
                        'aksesditolak',
                        'local_obe',
                        '',
                        null,
                        'Anda tidak memiliki hak akses untuk mengubah bobot kompetensi program studi lain.'
                    );
                }

                // Paksa prodi mengikuti prodi aktif
                $prodi = (int)$prodi_aktif->id;
            }

            $berhasil = bobot_relasi_manager::save_bobot(
                $id_kompetensi,
                $bobot,
                $tahun_akademik
            );

            if ($berhasil) {

                $msg = 'Bobot berhasil disimpan.';
                $type = \core\output\notification::NOTIFY_SUCCESS;

            } else {

                $msg = 'Gagal! Total bobot pada level ini melebihi 100%. Silakan cek sisa kuota induk.';
                $type = \core\output\notification::NOTIFY_ERROR;
            }

            redirect(
                new \moodle_url(
                    '/local/obe/views/admin/dashboard.php',
                    [
                        'halaman' => 'bobot_relasi',
                        'prodi'   => $prodi,
                        'id'      => $courseid
                    ]
                ),
                $msg,
                3,
                $type
            );
        }
    }
}