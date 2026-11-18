<?php
namespace local_obe\manager;

defined('MOODLE_INTERNAL') || die();

class bobot_relasi_manager {

    /**
     * Menyimpan atau memperbarui nilai bobot relasi kompetensi anak terhadap induknya.
     * Sudah dilengkapi pengaman batasan nilai akumulatif maksimal 100%.
     */
    public static function save_bobot($id_kompetensi, $bobot, $id_tahun_akademik = 0) {
        return \local_obe\local\assessment_integrity::atomic(static function() use($id_kompetensi,$bobot,$id_tahun_akademik) {return self::save_locked($id_kompetensi,$bobot,$id_tahun_akademik);});
    }
    private static function save_locked($id_kompetensi,$bobot,$id_tahun_akademik) {
        global $DB, $SESSION;

        // Jika tidak diberikan, gunakan tahun akademik aktif dari sesi
        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }
        //validasi bobot berada pada rentang 0-100
        $bobot = (float)$bobot;
        if (!is_finite($bobot) || $bobot < 0 || $bobot > 100) {
            throw new \moodle_exception('assessmenterror','local_obe','', 'Bobot relasi harus berada pada rentang 0 sampai 100.');
        }
    //
        $kompetensi_asal = $DB->get_record('local_obe_kompetensi', ['id' => $id_kompetensi]);
        if (!$kompetensi_asal) {
            return false;
        }

        if ((int)$kompetensi_asal->id_tahun_akademik!==(int)$id_tahun_akademik) {throw new \moodle_exception('assessmenterror','local_obe','', 'Tahun kompetensi tidak sesuai.');}
        \local_obe\access_guard::require_admin_or_kaprodi_capability('local/obe:manageprogram');
        $kap=\local_obe\access_guard::get_kaprodi_prodi();if ($kap&&(int)$kap->id!==(int)$kompetensi_asal->id_prodi) {throw new \moodle_exception('nopermissions','error');}
        $pf=$DB->get_record('local_obe_prodi_framework',['id_prodi'=>$kompetensi_asal->id_prodi,'id_tahun_akademik'=>$id_tahun_akademik],'*',MUST_EXIST);
        if ((int)$pf->frameworkid!==(int)$kompetensi_asal->frameworkid) {throw new \moodle_exception('assessmenterror','local_obe','', 'Framework kompetensi tidak sesuai.');}
        // Ambil data parent untuk menentukan tipe tujuan relasi hierarki
        $parent = $DB->get_record('local_obe_kompetensi', ['id' => $kompetensi_asal->parent_id]);
        $tipe_tujuan = $parent ? $parent->tipe : 'CPL';
        $expectedparents=['SUB-CPMK'=>'CPMK','CPMK'=>'INDIKATOR','INDIKATOR'=>'CPL'];
        if ($parent&&($expectedparents[$kompetensi_asal->tipe]??'')!==$parent->tipe) {throw new \moodle_exception('assessmenterror','local_obe','', 'Jenjang induk kompetensi tidak sesuai hierarki CPL → Indikator → CPMK → Sub-CPMK.');}

        if (($parent&&((int)$parent->id_prodi!==(int)$kompetensi_asal->id_prodi||(int)$parent->id_tahun_akademik!==(int)$id_tahun_akademik||(int)$parent->frameworkid!==(int)$kompetensi_asal->frameworkid))||(!$parent&&$kompetensi_asal->tipe!=='CPL')) {throw new \moodle_exception('assessmenterror','local_obe','', 'Induk kompetensi tidak sah untuk prodi/tahun/framework ini.');}


        // VALIDASI: Pastikan akumulasi jatah nilai saudara (siblings) + nilai input baru tidak over 100%
        $total_sekarang = self::get_total_bobot_siblings($id_kompetensi, $bobot, $id_tahun_akademik);
        if ($total_sekarang > 100.000001) { // Toleransi presisi nilai float 0.01
            return false;
        }

        $exist = $DB->get_record('local_obe_bobot_relasi', [
            'id_kompetensi_asal' => $id_kompetensi,
            'id_tahun_akademik'  => $id_tahun_akademik
        ]);
        
        if (!$exist||abs((float)$exist->nilai_bobot-$bobot)>0.000001) {\local_obe\local\assessment_integrity::protect_competency((int)$id_kompetensi);}
        $data = $exist ? $exist : new \stdClass();
        $data->id_kompetensi_asal   = $id_kompetensi;
        $data->tipe_asal             = $kompetensi_asal->tipe;
        $data->id_kompetensi_tujuan = $kompetensi_asal->parent_id;
        $data->tipe_tujuan           = $tipe_tujuan;
        $data->nilai_bobot           = (float)$bobot;
        $data->id_tahun_akademik     = $id_tahun_akademik;
        $data->waktu_ubah            = time();

        //memeriksa apakah sudah ada record sebelumnya, jika ada maka update, jika tidak maka insert
        if ($exist) {
            $result = $DB->update_record('local_obe_bobot_relasi', $data);
            \local_obe\access_guard::audit('update', 'bobot_relasi', $data->id, (array)$data);
            return $result;
        } else {
            $id = $DB->insert_record('local_obe_bobot_relasi', $data);
            \local_obe\access_guard::audit('create', 'bobot_relasi', $id, (array)$data);
            return $id;
        }
    }

    /**
     * Mengambil semua bobot relasi kompetensi untuk program studi tertentu dan tahun akademik tertentu.
     */
    public static function get_semua_bobot_by_prodi($prodi_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }
        $out=[];foreach (\local_obe\local\assessment_integrity::relations((int)$prodi_id,(int)$id_tahun_akademik) as $r) {$out[$r->id_kompetensi_asal]=$r->nilai_bobot;}return $out;
    }

    /**
     * Menghitung total bobot komptensi anak terhadap induknya
     */
    public static function get_total_bobot_terisi_by_parent($prodi_id, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $out=[];foreach (\local_obe\local\assessment_integrity::relations((int)$prodi_id,(int)$id_tahun_akademik) as $r) {$out[$r->parent_id]=($out[$r->parent_id]??0)+(float)$r->nilai_bobot;}return $out;
    }

    /**
     * Menghitung totoal bobot kompetensi yang berada pada level sama
     */
    public static function get_total_bobot_siblings($kompetensi_id, $bobot_baru, $id_tahun_akademik = 0) {
        global $DB, $SESSION;

        if (empty($id_tahun_akademik)) {
            $id_tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;
        }

        $k=$DB->get_record('local_obe_kompetensi',['id'=>$kompetensi_id],'*',MUST_EXIST);$total=0;
        foreach (\local_obe\local\assessment_integrity::relations((int)$k->id_prodi,(int)$id_tahun_akademik) as $r) {
            if ((int)$r->parent_id===(int)$k->parent_id&&(int)$r->id_kompetensi_asal!==(int)$kompetensi_id) {$total+=(float)$r->nilai_bobot;}
        }
        return $total+(float)$bobot_baru;
    }

    /**
     * Menghapus record bobot relasi berdasarkan ID
     */
    public static function delete_relasi($id) {
        return \local_obe\local\assessment_integrity::atomic(static function() use($id) {return self::delete_locked($id);});
    }
    private static function delete_locked($id) {
        global $DB;
        $old = $DB->get_record('local_obe_bobot_relasi', ['id' => $id], '*', IGNORE_MISSING);
        if ($old) {\local_obe\access_guard::require_admin_or_kaprodi_capability('local/obe:manageprogram');
            $k=$DB->get_record('local_obe_kompetensi',['id'=>$old->id_kompetensi_asal],'*',MUST_EXIST);
            $kap=\local_obe\access_guard::get_kaprodi_prodi();if($kap&&(int)$kap->id!==(int)$k->id_prodi) {throw new \moodle_exception('nopermissions','error');}
            \local_obe\local\assessment_integrity::protect_competency((int)$old->id_kompetensi_asal);
            \local_obe\local\assessment_integrity::archive('deleted_empty_relation',(int)$id,['relation'=>$old]);}
        $result = $DB->delete_records('local_obe_bobot_relasi', ['id' => $id]);
        \local_obe\access_guard::audit('delete', 'bobot_relasi', $id, $old ? (array)$old : []);
        return $result;
    }
    
}