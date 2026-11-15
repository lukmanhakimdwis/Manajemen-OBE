<?php
defined('MOODLE_INTERNAL') || die();

global $SESSION, $DB, $PAGE;

use local_obe\manager\prodi_manager;
use local_obe\manager\angkatan_manager;
use local_obe\manager\matakuliah_manager;
use local_obe\manager\relasikelas_manager;
use local_obe\manager\tahun_akademik_manager;

$id           = optional_param('id', 0, PARAM_INT);
$filter_prodi = optional_param('filter_prodi', 0, PARAM_INT); 

$list_tahun     = tahun_akademik_manager::ambil_semua();
$moodle_courses = relasikelas_manager::ambil_semua_course_moodle();

// 1. Identifikasi Peran Pengguna Aktif
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

// 2. Jika Mode Edit, Ambil Data Lama dan Jalankan Proteksi Anti-IDOR
$relasi = null;
$current_course_id = 0;
if ($id > 0) {
    $relasi = relasikelas_manager::ambil_satu($id);
    if ($relasi) {
        $current_course_id = (int)$relasi->id_kursus_moodle;
        
        // Tarik data MK untuk mengetahui kepemilikan prodi asal
        $mk_lama = $DB->get_record('local_obe_mata_kuliah', ['id' => $relasi->id_mata_kuliah]);
        if ($mk_lama) {
            $filter_prodi = (int)$mk_lama->id_prodi;
        }

        // =========================================================================
        // VALIDASI KEAMANAN (ANTI-IDOR): Cegah Manipulasi URL Manual (?id=xxx)
        // =========================================================================
        if ($prodi_aktif && $mk_lama) {
            if ((int)$mk_lama->id_prodi !== (int)$prodi_aktif->id) {
                throw new \moodle_exception(
                    'aksesditolak',
                    'local_obe',
                    '',
                    null,
                    'Gagal memuat form! Anda tidak memiliki hak akses untuk mengelola relasi kelas milik program studi lain.'
                );
            }
        }
    }
}

// 3. Kondisikan Pilihan Program Studi Berdasarkan Hak Akses
if ($prodi_aktif) {
    $prodis = [$prodi_aktif];
    $filter_prodi = (int)$prodi_aktif->id;
} else {
    $prodis = prodi_manager::ambil_semua_prodi();
}

// 4. Batasi Ambil Data Master Terfilter Hanya Berdasarkan Prodi Aktif
$matakuliah = ($filter_prodi > 0) ? matakuliah_manager::ambil_semua($filter_prodi) : [];
$angkatans  = ($filter_prodi > 0) ? angkatan_manager::ambil_semua($filter_prodi) : [];

// 5. Inisialisasi Autocomplete Searchable Moodle AMD
$is_multiple = ($id > 0) ? false : true; 
$PAGE->requires->js_call_amd('core/form-autocomplete', 'enhance', [
    '#id_kursus_moodle',
    $is_multiple, // True jika Tambah (Multiple), False jika Edit (Single)
    null,
    get_string('search') . '...',
    false,
    true
]);
?>

<div class="card border-0 shadow-sm rounded-lg mt-3">
    <div class="card-header bg-white p-4 border-bottom-0">
        <h5 class="mb-0 font-weight-bold text-primary">
            <i class="fa fa-<?= $id > 0 ? 'edit' : 'link' ?> mr-2"></i> <?= $id > 0 ? 'Ubah' : 'Tambah' ?> Relasi Kelas
        </h5>
        <p class="text-muted small mt-1 mb-0">Hubungkan mata kuliah kurikulum dengan kelas aktif di Moodle</p>
    </div>

    <div class="card-body p-4">
        <form id="form_relasi" action="<?= new moodle_url('/local/obe/views/admin/relasikelas/proses_relasikelas.php', ['aksi' => $id > 0 ? 'update' : 'simpan', 'id' => $id]) ?>" method="post">
            <input type="hidden" name="sesskey" value="<?= sesskey(); ?>">

            <div class="form-group mb-3">
                <label class="font-weight-bold">Program Studi <span class="text-danger">*</span></label>
                <?php if ($prodi_aktif): ?>
                    <input type="hidden" name="id_prodi" value="<?= $filter_prodi ?>">
                    <input type="text" class="form-control bg-light font-weight-bold" value="<?= s($prodi_aktif->nama_prodi) ?>" readonly>
                <?php else: ?>
                    <select name="id_prodi" id="select_prodi" class="form-control" required <?= $id > 0 ? 'disabled' : '' ?> onchange="reloadProdi(this.value)">
                        <option value="">-- Pilih Program Studi --</option>
                        <?php foreach ($prodis as $p): ?>
                            <option value="<?= $p->id ?>" <?= ($filter_prodi == $p->id) ? 'selected' : '' ?>><?= s($p->nama_prodi) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($id > 0): ?>
                        <input type="hidden" name="id_prodi" value="<?= $filter_prodi ?>">
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="form-group mb-3">
                <label class="font-weight-bold">Tahun Akademik <span class="text-danger">*</span></label>
                <select name="id_tahun_akademik" class="form-control" required>
                    <option value="">-- Pilih Tahun Akademik --</option>
                    <?php foreach ($list_tahun as $t): ?>
                        <option value="<?= $t->id ?>" <?= (($relasi && $relasi->id_tahun_akademik == $t->id) || (!$relasi && ($SESSION->obe_id_tahun_akademik ?? 0) == $t->id)) ? 'selected' : '' ?>>
                            <?= s($t->nama_tahun) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold">Mata Kuliah <span class="text-danger">*</span></label>
                        <select name="id_mata_kuliah" class="form-control" required <?= $filter_prodi == 0 ? 'disabled' : '' ?>>
                            <?php if ($filter_prodi == 0): ?>
                                <option value="">-- Pilih Prodi Terlebih Dahulu --</option>
                            <?php else: ?>
                                <option value="">-- Pilih Mata Kuliah --</option>
                                <?php foreach ($matakuliah as $mk): ?>
                                    <option value="<?= $mk->id ?>" <?= ($relasi && $relasi->id_mata_kuliah == $mk->id) ? 'selected' : '' ?>>
                                        <?= s($mk->nama_mk) ?> (<?= s($mk->kode_mk) ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold">Angkatan <span class="text-danger">*</span></label>
                        <select name="id_angkatan" class="form-control" required <?= $filter_prodi == 0 ? 'disabled' : '' ?>>
                            <?php if ($filter_prodi == 0): ?>
                                <option value="">-- Pilih Prodi Terlebih Dahulu --</option>
                            <?php else: ?>
                                <option value="">-- Pilih Angkatan --</option>
                                <?php foreach ($angkatans as $a): ?>
                                    <option value="<?= $a->id ?>" <?= ($relasi && $relasi->id_angkatan == $a->id) ? 'selected' : '' ?>>
                                        <?= s($a->nama_angkatan) ?> (<?= $a->tahun_masuk ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-group mb-4">
                <label class="font-weight-bold">Kursus Moodle (Target Kelas) <span class="text-danger">*</span></label>
                
                <?php if ($id > 0): ?>
                    <select name="id_kursus_moodle" id="id_kursus_moodle" class="form-control" required>
                        <?php if ($current_course_id > 0): 
                            $current_fullname = $DB->get_field('course', 'fullname', ['id' => $current_course_id], IGNORE_MISSING);
                            $current_shortname = $DB->get_field('course', 'shortname', ['id' => $current_course_id], IGNORE_MISSING);
                        ?>
                            <option value="<?= $current_course_id ?>" selected>
                                <?= s($current_fullname) ?> — (<?= s($current_shortname) ?>) [Data Saat Ini]
                            </option>
                        <?php endif; ?>

                        <?php foreach ($moodle_courses as $mc): ?>
                            <option value="<?= $mc->id ?>"><?= s($mc->fullname) ?> — (<?= s($mc->shortname) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <select name="id_kursus_moodle[]" id="id_kursus_moodle" multiple class="form-control" required <?= $filter_prodi == 0 ? 'disabled' : '' ?>>
                        <?php foreach ($moodle_courses as $mc): ?>
                            <option value="<?= $mc->id ?>"><?= s($mc->fullname) ?> — (<?= s($mc->shortname) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                
                <small class="form-text text-muted">
                    <?= $id > 0 ? 'Pilih kelas Moodle baru untuk mengubah relasi ini.' : 'Ketik nama kelas untuk mencari. Anda bisa memilih lebih dari satu kelas sekaligus.' ?>
                </small>
            </div>

            <div class="d-flex justify-content-end pt-3 border-top">
                <a href="?halaman=kelola_relasi<?= $filter_prodi > 0 ? '&id_prodi='.$filter_prodi : '' ?>" class="btn btn-light mr-2 px-4">Batal</a>
                <button type="submit" class="btn btn-primary px-4 shadow-sm" <?= $filter_prodi == 0 ? 'disabled' : '' ?>>
                    <i class="fa fa-save mr-1"></i> <?= $id > 0 ? 'Perbarui Relasi' : 'Simpan Relasi' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function reloadProdi(prodiId) {
    var url = new URL(window.location.href);
    if(prodiId) {
        url.searchParams.set('filter_prodi', prodiId);
    } else {
        url.searchParams.delete('filter_prodi');
    }
    window.location.href = url.toString();
}
</script>