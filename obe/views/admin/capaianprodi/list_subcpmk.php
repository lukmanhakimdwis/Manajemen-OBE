<?php
// File: local/obe/views/admin/capaianprodi/list_subcpmk.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_prodi_manager;
use local_obe\manager\prodi_manager;
use local_obe\manager\skala_nilai_manager;

global $SESSION, $DB, $USER;

// =====================================
// 1. TANGKAP PARAMETER & RETENSI STATE
// =====================================
$prodi_id     = optional_param('prodi', 0, PARAM_INT);
$cpmk_id      = optional_param('cpmk_id', 0, PARAM_INT);
$indikator_id = optional_param('indikator_id', 0, PARAM_INT);
$cpl_id       = optional_param('cpl_id', 0, PARAM_INT);
$angkatan_id  = optional_param('angkatan', 0, PARAM_INT); 

$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

// =========================================================================
// 2. PROTEKSI SEKURITI SERVER (ANTI-IDOR & INTERACTION SHIELD)
// =========================================================================
$is_kaprodi = prodi_manager::is_kaprodi($USER->id);

if ($is_kaprodi) {
    $prodiaktif = prodi_manager::ambil_prodi_kaprodi($USER->id);
    if (!$prodiaktif) {
        throw new moodle_exception('invalidrecord', 'local_obe', '', null, 'Akun Kaprodi belum terhubung dengan Program Studi.');
    }
    $prodi_id = (int)$prodiaktif->id;
}

if ($angkatan_id > 0) {
    if (!$DB->record_exists('local_obe_angkatan', ['id' => $angkatan_id])) {
        throw new moodle_exception('invalidparameter', 'local_obe', '', null, 'Data Angkatan tidak ditemukan.');
    }
}

if ($prodi_id > 0 && !$is_kaprodi) {
    if (!$DB->record_exists('local_obe_prodi', ['id' => $prodi_id])) {
        throw new moodle_exception('invalidparameter', 'local_obe', '', null, 'Data Program Studi tidak valid.');
    }
}

if ($cpl_id > 0) {
    $cpl_sah = $DB->record_exists('local_obe_kompetensi', ['id' => $cpl_id, 'id_prodi' => $prodi_id, 'tipe' => 'CPL']);
    if (!$cpl_sah) {
        throw new moodle_exception('aksesditolak', 'local_obe', '', null, 'Struktur CPL tidak sah atau tidak sesuai dengan Program Studi.');
    }
}

if ($indikator_id > 0) {
    $indikator = $DB->get_record('local_obe_kompetensi', ['id' => $indikator_id, 'id_prodi' => $prodi_id, 'tipe' => 'INDIKATOR'], 'id, parent_id');
    if (!$indikator) {
        throw new moodle_exception('aksesditolak', 'local_obe', '', null, 'Struktur Indikator tidak ditemukan.');
    }
    if ($cpl_id > 0 && (int)$indikator->parent_id !== (int)$cpl_id) {
        throw new moodle_exception('aksesditolak', 'local_obe', '', null, 'Pelanggaran struktur: Indikator tidak cocok dengan CPL Induk.');
    }
}

if ($cpmk_id > 0) {
    $cpmk_check = $DB->get_record('local_obe_kompetensi', ['id' => $cpmk_id, 'id_prodi' => $prodi_id, 'tipe' => 'CPMK'], 'id, parent_id');
    if (!$cpmk_check) {
        throw new moodle_exception('aksesditolak', 'local_obe', '', null, 'Struktur CPMK tidak ditemukan.');
    }
    if ($indikator_id > 0 && (int)$cpmk_check->parent_id !== (int)$indikator_id) {
        throw new moodle_exception('aksesditolak', 'local_obe', '', null, 'Pelanggaran struktur: CPMK tidak cocok dengan Indikator Induk.');
    }
}

// =========================================================================
// 3. AMBIL DATA SUMMARY INDUK LENGKAP (CPMK & ANGKATAN DATA)
// =========================================================================
$cpmk_record = $DB->get_record('local_obe_kompetensi', ['id' => $cpmk_id, 'tipe' => 'CPMK'], 'id, nama_kompetensi, deskripsi');
$nama_prodi_header = $DB->get_field('local_obe_prodi', 'nama_prodi', ['id' => $prodi_id]);

// =====================================
// 4. TARIK DATA UTAMA (TERFILTER ANGKATAN)
// =====================================
$data = [];
if ($prodi_id > 0 && $cpmk_id > 0) {
    $data = capaian_prodi_manager::ambil_capaian_subcpmk_prodi(
        $prodi_id,
        $cpmk_id,
        $tahun_akademik,
        $angkatan_id
    );
}

// =====================================
// 5. KONSTRUKSI CONTEXTUAL BACK & GRAFIK URL
// =====================================
$url_kembali = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman'      => 'capaian_pembelajaran_prodi',
    'sub'          => 'cpmk',
    'prodi'        => $prodi_id,
    'angkatan'     => $angkatan_id,
    'indikator_id' => $indikator_id,
    'cpl_id'       => $cpl_id
]);

$url_grafik = new moodle_url('/local/obe/views/admin/dashboard.php', [
    'halaman'      => 'capaian_pembelajaran_prodi',
    'sub'          => 'grafik_subcpmk',
    'prodi'        => $prodi_id,
    'angkatan'     => $angkatan_id,
    'cpl_id'       => $cpl_id,
    'indikator_id' => $indikator_id,
    'cpmk_id'      => $cpmk_id,
    'sesskey'      => sesskey()
]);

// Fungsi Formatter Nilai Desimal Efisien
$format_nilai = function($nilai, $desimal = 2) {
    if (!is_numeric($nilai)) {
        return $nilai;
    }

    return (floor($nilai) == $nilai)
        ? (int)$nilai
        : rtrim(rtrim(number_format($nilai, $desimal, '.', ''), '0'), '.');
};
?>

<div class="card shadow-sm border-0 mb-4 bg-white rounded">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap">
            
            <div style="flex: 1; min-width: 250px;">
                
                <div class="d-flex align-items-center flex-wrap mb-3">
                    <h4 class="font-weight-bold mb-0 text-dark" style="font-size: 1.35rem; color: #000000 !important; letter-spacing: -0.3px;">
                        <i class="fa fa-layer-group text-primary mr-2"></i>Capaian Sub-CPMK Program Studi
                    </h4>
                    
                    <?php if (!empty($nama_prodi_header)): ?>
                        <span class="badge badge-light border text-muted px-2 py-1 small ml-3 font-weight-medium" style="background: #f8f9fa; border-radius: 6px; color: #212529 !important;">
                            <i class="fa fa-university text-muted mr-1"></i><?= s($nama_prodi_header) ?>
                        </span>
                    <?php endif; ?>

                </div>

                <?php if ($cpmk_record): ?>
                    <h6 class="mb-1 text-dark" style="color: #000000 !important; font-weight: 700;">
                        <i class="fa fa-cube text-primary mr-2"></i>CPMK: <?= s($cpmk_record->nama_kompetensi) ?>
                    </h6>

                    <?php if (!empty($cpmk_record->deskripsi)): ?>
                        <div class="text-secondary small ml-4 mb-1" style="color: #495057 !important; line-height: 1.5; max-width: 850px; font-style: normal !important;">
                            <?= s(strip_tags($cpmk_record->deskripsi)) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

            </div>

            <div class="ml-3 flex-shrink-0 pt-1 d-flex align-items-center" style="gap: 6px;">
                <a href="<?= $url_grafik->out() ?>" class="btn btn-sm btn-primary font-weight-bold shadow-sm px-3 py-2" style="border-radius: 6px; font-size: 0.8rem;">
                    <i class="fa fa-bar-chart mr-1"></i>Grafik Sub-CPMK
                </a>
                <a href="<?= $url_kembali->out() ?>" class="btn btn-sm btn-secondary font-weight-bold shadow-sm px-3 py-2" style="border-radius: 6px; font-size: 0.8rem;">
                    <i class="fa fa-arrow-left mr-1"></i>Kembali ke CPMK
                </a>
            </div>

        </div>
    </div>
</div>

<?php if ($prodi_id == 0 || $cpmk_id == 0): ?>
    <div class="alert alert-danger text-center py-4 border-0 shadow-sm" style="border-radius: 8px;">
        <i class="fa fa-exclamation-triangle fa-2x mb-2 d-block"></i>
        <h5 class="font-weight-bold">Parameter Tidak Lengkap</h5>
        <p class="mb-0 small">Gunakan menu navigasi utama dashboard untuk melihat rincian capaian Sub-CPMK secara terstruktur.</p>
    </div>
<?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="table-responsive bg-white rounded p-3">
            <table class="table table-striped table-hover align-middle mb-0 border">
                <thead class="thead-light">
                    <tr class="text-uppercase small" style="letter-spacing: 0.5px;">
                        <th class="py-3 pl-4" style="width: 25%;">Nama Sub-CPMK</th>
                        <th style="width: 34%;">Deskripsi Materi</th>
                        <th class="text-center" style="width: 11%;">Bobot</th>
                        <th class="text-center" style="width: 12%;">Rata-Rata Capaian</th>
                        <th class="text-center" style="width: 10%;">Grade</th>
                        <th class="text-center" style="width: 10%;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fa fa-ban fa-2x d-block mb-2 text-secondary"></i>
                                Belum ada data pencapaian Sub-CPMK prodi yang terekam untuk kriteria angkatan ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($data as $sub): ?>
                            <?php
                            if (isset($sub->grade)) {
                                $grade_raw = $sub->grade;
                            } elseif (isset($sub->grade_subcpmk)) {
                                $grade_raw = $sub->grade_subcpmk;
                            } else {
                                $grade_raw = skala_nilai_manager::cek_skala($sub->percentage, 'SUB-CPMK', $prodi_id, $tahun_akademik);
                            }

                            $grade_tampil = !empty($grade_raw) ? strtoupper(trim($grade_raw)) : '-';
                            $badge = 'secondary';

                            switch ($grade_tampil) {
                                case 'A': case 'AB': case 'SB': $badge = 'success'; break;
                                case 'B': case 'BC':             $badge = 'primary'; break;
                                case 'C':                         $badge = 'warning'; break;
                                case 'D': case 'E': case 'K':    $badge = 'danger'; break;
                            }
                            
                            $sub_id = $sub->id ?? $sub->subcpmk_id ?? 0;
                            ?>
                            <tr>
                                <td class="pl-4 py-3 text-break" style="font-size: 0.95rem; color: #212529 !important; font-weight: 600;">
                                    <i class="fa fa-caret-right text-info mr-2"></i><?= s($sub->nama_subcpmk ?? $sub->nama) ?>
                                </td>
                                
                                <td class="py-3 text-secondary small" style="line-height: 1.5; color: #495057 !important;">
                                    <?php if (!empty($sub->deskripsi)): ?>
                                        <?= s(strip_tags($sub->deskripsi)) ?>
                                    <?php else: ?>
                                        <?php 
                                        $desc_db = $DB->get_field('local_obe_kompetensi', 'deskripsi', ['id' => $sub_id]); 
                                        echo !empty($desc_db) ? s(strip_tags($desc_db)) : '<span class="text-muted font-italic">-</span>';
                                        ?>
                                    <?php endif; ?>
                                </td>
                                
                                <td class="text-center py-3">
                                    <span class="badge badge-secondary font-weight-bold px-2 py-1" style="font-size: 0.8rem; background-color: #e9ecef; color: #495057; border: 1px solid #ced4da;">
                                        <?= $format_nilai($sub->bobot, 0) ?>%
                                    </span>
                                </td>
                                
                                <td class="text-center py-3">
                                    <span class="h5 font-weight-bold mb-0" style="color: #000000 !important; font-size: 1.05rem;">
                                        <?= $format_nilai($sub->percentage) ?>
                                    </span>
                                </td>
                                
                                <td class="text-center py-3">
                                    <span class="badge badge-<?= $badge ?> px-3 py-1.5" style="font-size: 0.85rem; font-weight: 600; min-width: 50px; letter-spacing: 0.5px; border-radius: 4px;">
                                        <?= s($grade_tampil) ?>
                                    </span>
                                </td>

                                <td class="text-center py-3">
                                    <?php
                                    $url_detail = new moodle_url('/local/obe/views/admin/dashboard.php', [
                                        'halaman'       => 'capaian_pembelajaran_prodi',
                                        'sub'           => 'detail_nilai_subcpmk',
                                        'prodi'         => $prodi_id,
                                        'angkatan'      => $angkatan_id,
                                        'cpl_id'        => $cpl_id,
                                        'indikator_id'  => $indikator_id,
                                        'cpmk_id'       => $cpmk_id,
                                        'kompetensi_id' => $sub_id,
                                        'tipe'          => 'SUB-CPMK',
                                        'sesskey'       => sesskey()
                                    ]);
                                    ?>
                                    <a href="<?= $url_detail->out() ?>"
                                       class="btn btn-sm btn-info d-inline-flex align-items-center justify-content-center p-0 rounded-circle shadow-sm"
                                       style="width:30px;height:30px;"
                                       title="Detail Nilai Mahasiswa">
                                        <i class="fa fa-users" style="font-size:11px;"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>