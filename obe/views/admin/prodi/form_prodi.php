<?php
// File view: Form Tambah / Edit Program Studi Poliwangi
defined('MOODLE_INTERNAL') || die();

global $DB, $PAGE;

use local_obe\manager\prodi_manager;

$id_edit = optional_param('id', 0, PARAM_INT);
$data = $id_edit ? prodi_manager::ambil_prodi_berdasarkan_id($id_edit) : null;
$aksi = $id_edit ? 'update' : 'simpan';

// Kaprodi yang sedang dipilih (jika edit).
$current_kaprodi_id = $data->userid_kaprodi ?? 0;

// Ambil semua user aktif Moodle yang BELUM menjadi Kaprodi
// di prodi lain. Jika sedang edit, Kaprodi yang saat ini
// digunakan tetap ditampilkan.
$sql = "
    SELECT DISTINCT
        u.id,
        u.firstname,
        u.lastname
    FROM {user} u
    JOIN {role_assignments} ra
        ON ra.userid = u.id
    JOIN {role} r
        ON r.id = ra.roleid
    WHERE u.deleted = 0
      AND u.suspended = 0
      AND u.id <> 1
      AND r.shortname <> 'student'
      AND (
            u.id NOT IN (
                SELECT userid_kaprodi
                FROM {local_obe_prodi}
                WHERE userid_kaprodi IS NOT NULL
                  AND userid_kaprodi <> 0
                  " . ($id_edit ? "AND id <> :prodiid" : "") . "
            )
            " . ($id_edit ? "OR u.id = :currentkaprodi" : "") . "
      )
    ORDER BY u.firstname ASC, u.lastname ASC
";

$params = [];

if ($id_edit) {
    $params['prodiid'] = $id_edit;
    $params['currentkaprodi'] = $current_kaprodi_id;
}

$users = $DB->get_records_sql($sql, $params);

// Aktifkan autocomplete Moodle.
$PAGE->requires->js_call_amd(
    'core/form-autocomplete',
    'enhance',
    ['#userid_kaprodi']
);
?>

<div class="card border-0 shadow-sm rounded-lg mt-3">
    <div class="card-header bg-white border-0 pt-4 pb-0">
        <h5 class="font-weight-bold text-primary">
            <i class="fa fa-<?= $id_edit ? 'edit' : 'plus-circle'; ?> mr-2"></i>
            <?= $id_edit ? 'Edit Program Studi' : 'Tambah Program Studi Baru'; ?>
        </h5>
    </div>

    <div class="card-body">
        <form action="?halaman=kelola_prodi&aksi=<?= $aksi; ?>" method="post">

            <input type="hidden" name="sesskey" value="<?= sesskey(); ?>">
            <input type="hidden" name="id" value="<?= $id_edit; ?>">

            <div class="row">
                <div class="col-md-4">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold">
                            Kode Prodi <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="kode_prodi"
                            class="form-control"
                            value="<?= $data ? s($data->kode_prodi) : ''; ?>"
                            placeholder="Contoh: D4-TI"
                            required>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold">
                            Nama Program Studi <span class="text-danger">*</span>
                        </label>
                        <input
                            type="text"
                            name="nama_prodi"
                            class="form-control"
                            value="<?= $data ? s($data->nama_prodi) : ''; ?>"
                            placeholder="Contoh: Teknologi Rekayasa Perangkat Lunak"
                            required>
                    </div>
                </div>
            </div>

            <div class="form-group mb-3">
                <label class="font-weight-bold">
                    Ketua Program Studi (Kaprodi)
                </label>

                <select
                    name="userid_kaprodi"
                    id="userid_kaprodi"
                    class="form-control">

                    <option value="0">
                        -- Belum Ditentukan --
                    </option>

                    <?php foreach ($users as $user): ?>
                        <option
                            value="<?= $user->id ?>"
                            <?= ($current_kaprodi_id == $user->id) ? 'selected' : '' ?>>
                            <?= fullname($user) ?>
                        </option>
                    <?php endforeach; ?>

                </select>

                <small class="form-text text-muted">
                    Pilih akun Moodle yang menjabat sebagai Kaprodi pada program studi ini.
                </small>
            </div>

            <div class="form-group mb-4">
                <label class="font-weight-bold">Keterangan</label>
                <textarea
                    name="keterangan"
                    class="form-control"
                    rows="3"
                    placeholder="Deskripsi singkat prodi..."><?= $data ? s($data->keterangan) : ''; ?></textarea>
            </div>

            <div class="d-flex justify-content-end border-top pt-3">
                <a href="?halaman=kelola_prodi" class="btn btn-light px-4 mr-2">
                    Batal
                </a>

                <button type="submit" class="btn btn-primary px-4 shadow-sm">
                    <i class="fa fa-save mr-1"></i> Simpan
                </button>
            </div>

        </form>
    </div>
</div>