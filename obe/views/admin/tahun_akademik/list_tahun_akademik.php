<?php
defined('MOODLE_INTERNAL') || die();

global $SESSION;
use local_obe\manager\tahun_akademik_manager;
$daftar_tahun =
    tahun_akademik_manager::ambil_semua();
$tahun_aktif_id =
    $SESSION->obe_id_tahun_akademik ?? 0;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">
        Daftar Tahun Akademik
    </h4>
    <a href="?halaman=tahun_akademik&sub=form"
       class="btn btn-primary btn-sm">
        <i class="fa fa-plus mr-1"></i>
        Tambah Tahun
    </a>
</div>
<div class="table-responsive bg-white shadow-sm rounded p-3">
    <table class="table table-hover align-middle mb-0">
        <thead class="thead-light">
            <tr>
                <th style="width: 5%;">
                    No
                </th>
                <th>
                    Nama Tahun Akademik
                </th>
                <th style="width: 20%;">
                    Status Session
                </th>
                <th style="width: 12%;"
                    class="text-center">
                    Aksi
                </th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($daftar_tahun)): ?>
                <tr>
                    <td colspan="4"
                        class="text-center text-muted py-5">
                        <i class="fa fa-calendar-times-o fa-2x mb-2 d-block"></i>
                        Belum ada data tahun akademik.
                    </td>
                </tr>
            <?php else: ?>
                <?php $no = 1; ?>
                <?php foreach ($daftar_tahun as $t): ?>
                    <?php
                    $is_active =
                        ($tahun_aktif_id == $t->id);
                    $memilikirelasi =
                        tahun_akademik_manager
                            ::memiliki_relasi($t->id);
                    ?>
                    <tr>
                        <td>
                            <?= $no++ ?>
                        </td>
                        <td class="font-weight-bold">
                            <?= s($t->nama_tahun) ?>
                        </td>
                        <td>
                            <?php if ($is_active): ?>
                                <span class="badge badge-success px-3 py-2">
                                    <i class="fa fa-check-circle mr-1"></i>
                                    Sedang Digunakan
                                </span>
                            <?php else: ?>
                                <span class="badge badge-secondary px-3 py-2">
                                    Tidak Aktif
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($memilikirelasi): ?>
                                <button type="button"
                                        class="btn btn-secondary btn-sm"
                                        disabled
                                        title="Tahun akademik memiliki relasi">
                                    <i class="fa fa-lock"></i>
                                </button>
                            <?php else: ?>
                                <a href="<?= new moodle_url(
                                    '/local/obe/views/admin/tahun_akademik/proses.php',
                                    [
                                        'aksi' => 'hapus',
                                        'id' => $t->id,
                                        'sesskey' => sesskey()
                                    ]
                                ) ?>"
                                class="btn btn-danger btn-sm"
                                title="Hapus"
                                onclick="return confirm('Hapus tahun akademik ini?')">
                                    <i class="fa fa-trash"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>