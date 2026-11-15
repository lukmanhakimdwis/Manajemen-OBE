<?php
// File: local/obe/views/admin/capaian_kelas/detail_mahasiswa.php
defined('MOODLE_INTERNAL') || die();

use local_obe\manager\capaian_kelas_manager;
use local_obe\manager\prodi_manager;
use local_obe\manager\kompetensi_manager;

global $COURSE, $DB, $OUTPUT, $SESSION, $USER;

// =====================================================
// 1. IDENTIFIKASI MODE VIEW
// =====================================================
$mode_view = $mode_view ?? '';
$is_dosen_mode = ($mode_view === 'dosen');
$is_mahasiswa_mode = ($mode_view === 'mahasiswa');

// =====================================================
// 2. TANGKAP PARAMETER MASUKAN
// =====================================================
$courseid = optional_param('id_course', optional_param('id', 0, PARAM_INT), PARAM_INT);
$sub_cpmk_id = optional_param('sub_cpmk_id', 0, PARAM_INT);
$cpmk_id = optional_param('cpmk_id', 0, PARAM_INT);
$indikator_id = optional_param('indikator_id', 0, PARAM_INT);
$cpl_id = optional_param('cpl_id', 0, PARAM_INT); // Menjaga state alur kembali ke hulu kurikulum

if ($courseid == 0 && isset($COURSE)) {
    $courseid = $COURSE->id;
}

$tahun_akademik = $SESSION->obe_id_tahun_akademik ?? 0;

if (empty($sub_cpmk_id)) {
    throw new moodle_exception('invalidrecord', 'error', '', null, 'ID Sub CPMK tidak valid atau kosong.');
}

// =========================================================================
// 3. VALIDASI KEAMANAN SERVER (ANTI-IDOR DATA LOCK SHIELD)
// =========================================================================
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

// Jika mode dosen, jangan batasi berdasarkan prodi kaprodi.
if ($prodi_aktif && !$is_dosen_mode) {
    $subcpmk = kompetensi_manager::ambil_by_id($sub_cpmk_id);

    if (!$subcpmk) {
        throw new moodle_exception('invalidrecord', 'error', '', null, 'Data Sub CPMK tidak ditemukan.');
    }

    // Validasi Lapis 1: Sub CPMK target harus terikat dengan prodi naungan Kaprodi
    if ((int)$subcpmk->id_prodi !== (int)$prodi_aktif->id) {
        throw new moodle_exception(
            'aksesditolak',
            'local_obe',
            '',
            null,
            'Aksi ditolak! Anda tidak memiliki hak akses untuk melihat data Sub CPMK program studi lain.'
        );
    }

    // Validasi Lapis 2: Kelas Moodle target harus diselenggarakan oleh prodi naungan Kaprodi
    $sql_relasi = "SELECT rk.id
                     FROM {local_obe_relasi_kelas} rk
                     JOIN {local_obe_mata_kuliah} mk ON rk.id_mata_kuliah = mk.id
                    WHERE rk.id_kursus_moodle = :courseid
                      AND mk.id_prodi = :prodiid";

    $relasi_valid = $DB->get_record_sql($sql_relasi, [
        'courseid' => $courseid,
        'prodiid'  => $prodi_aktif->id
    ]);

    if (!$relasi_valid) {
        throw new moodle_exception(
            'aksesditolak',
            'local_obe',
            '',
            null,
            'Aksi ditolak! Anda tidak memiliki hak akses untuk melihat data kelas program studi lain.'
        );
    }
}

// =========================================================================
// 4. RETRIEVE DATA UTAMA (MENGGUNAKAN SINGLE SOURCE OF TRUTH BERPOLA BINER)
// =========================================================================
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Ambil payload super lengkap hasil kalkulasi engine manager (Sesuai Usulan 2)
$data = capaian_kelas_manager::ambil_detail_kompetensi_mahasiswa(
    $courseid,
    $sub_cpmk_id,
    'SUB-CPMK',
    $tahun_akademik
);

$ringkasan = $data->ringkasan;
$users = $data->detail_mahasiswa ?? [];

// Formatter dinamis untuk menghilangkan trailing zeros pada pecahan desimal
$format_angka = function($nilai, $desimal = 2) {
    if (!is_numeric($nilai)) {
        return $nilai;
    }
    return (floor($nilai) == $nilai)
        ? (int)$nilai
        : rtrim(rtrim(number_format($nilai, $desimal, '.', ''), '0'), '.');
};

// 5. Filter Mahasiswa (Jika antarmuka diakses oleh Mahasiswa)
if ($is_mahasiswa_mode) {
    $users = array_values(array_filter($users, function($u) use ($USER) {
        return (int)$u->id === (int)$USER->id;
    }));
}

// =====================================================
// 6. CONFIG TOMBOL KEMBALI (DURABLE ROUTING)
// =====================================================
$back_params = [
    'cpmk_id'      => $cpmk_id,
    'indikator_id' => $indikator_id,
    'cpl_id'       => $cpl_id
];

if ($is_mahasiswa_mode) {
    $back_params['id_course'] = $courseid;
    $back_params['halaman'] = 'subcpmk';
    $back_url = new moodle_url('/local/obe/views/mahasiswa/dashboard.php', $back_params);
} elseif ($is_dosen_mode) {
    $back_params['id_course'] = $courseid;
    $back_params['halaman'] = 'laporan';
    $back_params['sub'] = 'subcpmk';
    $back_url = new moodle_url('/local/obe/views/dosen/dashboard.php', $back_params);
} else {
    $back_params['id'] = $courseid;
    $back_params['id_course'] = $courseid;
    $back_params['halaman'] = 'capaian_pembelajaran_kelas';
    $back_params['sub'] = 'subcpmk';
    $back_url = new moodle_url('/local/obe/views/admin/dashboard.php', $back_params);
}
?>

<div class="card mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0 font-weight-bold text-dark">
                <i class="fa fa-info-circle text-primary mr-2"></i>Informasi Struktur & Pemetaan OBE
            </h5>
            <a href="<?= $back_url->out() ?>" class="btn btn-sm btn-outline-secondary font-weight-bold shadow-sm">
                <i class="fa fa-arrow-left mr-1"></i> Kembali
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="row align-items-center m-0">
            <div class="col-md-5 border-right px-0">
                <ul class="list-unstyled mb-0 text-dark" style="line-height: 1.8;">
                    <li><b>CPL:</b> <?= $data->cpl_record ? s($data->cpl_record->nama_kompetensi) : '-' ?></li>
                    <li><b>Indikator:</b> <?= $data->indikator_record ? s($data->indikator_record->nama_kompetensi) : '-' ?></li>
                    <li><b>CPMK:</b> <?= $data->cpmk_record ? s($data->cpmk_record->nama_kompetensi) : '-' ?></li>
                    <li><b>Sub CPMK:</b> <span class="text-primary font-weight-bold"> <?= s($data->sub_cpmk->nama_kompetensi) ?> </span>
                        <?php if (!empty($data->sub_cpmk->deskripsi)): ?>
                             <div class="text-muted small mt-1 ml-5"> <?= format_text($data->sub_cpmk->deskripsi, FORMAT_HTML) ?> </div>
                        <?php endif; ?></li>
                </ul>
            </div>

            <div class="col-md-4 border-right text-center">
                <div class="row mb-2 m-0">
                    <div class="col-4">
                        <h4 class="font-weight-bold text-dark mb-1"><?= $format_angka($data->bobot_sub_display) ?>%</h4>
                        <small class="text-muted text-uppercase d-block font-weight-bold" style="font-size:0.65rem;">Sub &rarr; CPMK</small>
                    </div>
                    <div class="col-4">
                        <h4 class="font-weight-bold text-dark mb-1"><?= $format_angka($data->bobot_cpmk_display) ?>%</h4>
                        <small class="text-muted text-uppercase d-block font-weight-bold" style="font-size:0.65rem;">CPMK &rarr; Ind</small>
                    </div>
                    <div class="col-4">
                        <h4 class="font-weight-bold text-dark mb-1"><?= $format_angka($data->bobot_ind_display) ?>%</h4>
                        <small class="text-muted text-uppercase d-block font-weight-bold" style="font-size:0.65rem;">Ind &rarr; CPL</small>
                    </div>
                </div>
            </div>

            <?php if (!$is_mahasiswa_mode): ?>
                <div class="col-md-3 text-center px-0">
                    <span class="text-muted small font-weight-bold text-uppercase d-block mb-1" style="letter-spacing: 0.5px; font-size: 0.7rem;">Rata-Rata Kelas</span>
                    <h2 class="font-weight-bold text-primary mb-0"><?= $format_angka($ringkasan->rata_rata) ?></h2>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4 border-0">
    <div class="table-responsive bg-white rounded p-3">
        <table class="table table-hover align-middle mb-0">
            <thead class="thead-light">
                <tr class="text-uppercase small text-center" style="letter-spacing: 0.5px;">
                    <th class="text-left py-3 pl-4" style="width: 20%;">Nama Mahasiswa</th>
                    <th class="text-left py-3" style="width: 35%;">Rincian Komponen Aktivitas</th>
                    <th class="py-3" style="width: 12%;">Nilai Sub CPMK</th>
                    <th class="py-3" style="width: 15%;">Kontribusi CPMK</th>
                    <th class="py-3" style="width: 8%;">Grade</th>
                    <th class="py-3" style="width: 13%;">Status Kelulusan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="fa fa-inbox fa-2x d-block mb-2 text-secondary"></i>
                            Belum ada data nilai mahasiswa untuk ditampilkan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <?php
                        $badge_class = 'secondary';
                        switch (strtoupper(trim($user->grade))) {
                            case 'A': case 'AB': case 'SB': $badge_class = 'success'; break;
                            case 'B': case 'BC':             $badge_class = 'primary'; break;
                            case 'C':                         $badge_class = 'warning'; break;
                            case 'D': case 'E': case 'K':    $badge_class = 'danger'; break;
                        }
                        ?>
                        <tr class="text-center">
                            <td class="text-left pl-4 py-3">
                                <div class="font-weight-bold text-dark">
                                    <?= s($user->nama) ?>
                                </div>
                                <div class="text-muted small">
                                    <i class="fa fa-user mr-1"></i>
                                    <?= s($user->username) ?>
                                </div>
                            </td>
                            <td class="text-left py-3">
                                <?php if (empty($user->rincian_list)): ?>
                                    <small class="text-muted"><i class="fa fa-info-circle mr-1"></i> Tidak ada aktivitas pendukung.</small>
                                <?php else: ?>
                                    <?php foreach ($user->rincian_list as $r): ?>
                                        <div class="mb-3 pb-2 border-bottom text-dark" style="font-size: 0.9rem;">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div>
                                                    <i class="fa fa-caret-right text-info mr-2"></i>
                                                    <span class="text-capitalize font-weight-bold text-dark mr-1"><?= s($r->nama_aktivitas ?? '') ?></span>
                                                    <span class="badge badge-light border text-dark font-weight-bold px-2 py-1" style="font-size: 10px;">
                                                        <?= ucfirst($r->modulename) ?>
                                                    </span>
                                                </div>
                                                <div class="text-dark font-weight-bold small">
                                                    Bobot: <?= $format_angka($r->bobot_aktivitas) ?>%
                                                </div>
                                            </div>
                                            
                                            <div class="row bg-light rounded m-0 p-2 text-center" style="font-size: 0.8rem; border: 1px solid #e3e6f0;">
                                                <div class="col-4 border-right" style="border-right-color: #e3e6f0 !important;">
                                                    <span class="text-dark font-weight-bold d-block mb-1" style="font-size: 10px;">Nilai Aktivitas</span>
                                                    <span class="text-primary font-weight-bold" style="font-size: 0.95rem;"><?= $format_angka($r->skor, 1) ?></span>
                                                </div>
                                                <div class="col-4 border-right" style="border-right-color: #e3e6f0 !important;">
                                                    <span class="text-dark font-weight-bold d-block mb-1" style="font-size: 10px;">Skor Maksimal</span>
                                                    <span class="text-dark font-weight-bold" style="font-size: 0.95rem;"><?= $format_angka($r->max, 0) ?></span>
                                                </div>
                                                <div class="col-4">
                                                    <span class="text-dark font-weight-bold d-block mb-1" style="font-size: 10px;">Kontribusi Subcpmk</span>
                                                    <span class="text-success font-weight-bold" style="font-size: 0.95rem;"><?= $format_angka($r->nilai_terbobot) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center py-3">
                                <h5 class="text-primary font-weight-bold mb-0">
                                    <?= $format_angka($user->total_nilai_obe) ?>
                                </h5>
                                
                            </td>
                            <td class="text-center py-3">
                                <h5 class="text-success font-weight-bold mb-1">
                                    <?= $format_angka($user->kontribusi_cpmk) ?>
                                </h5>
                                
                            </td>
                            <td class="text-center py-3">
                                <span class="badge badge-<?= $badge_class ?> px-3 py-2" style="font-size: 0.85rem; font-weight: 600; min-width: 45px;">
                                    <?= s($user->grade) ?>
                                </span>
                            </td>
                            <td class="text-center py-3">
                                <?php if ($user->kompeten): ?>
                                    <span class="badge badge-success px-3 py-2 font-weight-bold" style="font-size: 0.82rem; letter-spacing: 0.3px;">
                                        <i class="fa fa-check-circle mr-1"></i> KOMPETEN
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-danger px-3 py-2 font-weight-bold" style="font-size: 0.82rem; letter-spacing: 0.3px;">
                                        <i class="fa fa-times-circle mr-1"></i> BELUM KOMPETEN
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>