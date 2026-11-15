<?php
defined('MOODLE_INTERNAL') || die();

global $DB;

use local_obe\manager\prodi_manager;

// Ambil data menggunakan Manager
$prodis = prodi_manager::ambil_semua_prodi();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Daftar Program Studi</h4>

    <a href="?halaman=kelola_prodi&sub=form"
       class="btn btn-primary btn-sm shadow-sm">
        <i class="fa fa-plus mr-1"></i>
        Tambah Prodi
    </a>
</div>

<div class="table-responsive bg-white p-3 rounded shadow-sm border-0">
    <table class="table table-hover align-middle mb-0">

        <thead class="thead-light">
            <tr>
                <th width="12%">Kode</th>
                <th width="25%">Nama Program Studi</th>
                <th width="20%">Kaprodi</th>
                <th>Keterangan</th>
                <th width="15%" class="text-center">Aksi</th>
            </tr>
        </thead>

        <tbody>

            <?php if (empty($prodis)): ?>

                <tr>
                    <td colspan="5" class="text-center text-muted py-5">
                        <i class="fa fa-info-circle fa-2x mb-2 d-block"></i>
                        Belum ada data program studi.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach ($prodis as $p): ?>

                    <?php
                    $memilikirelasi = prodi_manager::memiliki_relasi($p->id);

                    $kaprodi = null;

                    if (!empty($p->userid_kaprodi)) {
                        $kaprodi = $DB->get_record(
                            'user',
                            ['id' => $p->userid_kaprodi],
                            'id, firstname, lastname'
                        );
                    }
                    ?>

                    <tr>

                        <td>
                            <span class="font-weight-bold text-primary">
                                <?= s($p->kode_prodi); ?>
                            </span>
                        </td>

                        <td>
                            <span class="font-weight-bold">
                                <?= s($p->nama_prodi); ?>
                            </span>
                        </td>

                        <td>
                            <?php if ($kaprodi): ?>

                                <span class="badge badge-info px-3 py-2">
                                    <?= fullname($kaprodi); ?>
                                </span>

                            <?php else: ?>

                                <span class="text-muted font-italic">
                                    Belum ditentukan
                                </span>

                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($p->keterangan)): ?>

                                <?= s($p->keterangan); ?>

                            <?php else: ?>

                                <span class="text-muted font-italic">
                                    Tidak ada keterangan
                                </span>

                            <?php endif; ?>
                        </td>

                        <td class="text-center">

                            <!-- Tombol Edit -->
                            <a href="?halaman=kelola_prodi&sub=form&id=<?= $p->id; ?>"
                               class="btn btn-warning btn-sm shadow-sm"
                               title="Edit Program Studi">

                                <i class="fa fa-edit"></i>

                            </a>

                            <!-- Tombol Hapus / Lock -->
                            <?php if ($memilikirelasi): ?>

                                <button type="button"
                                        class="btn btn-secondary btn-sm shadow-sm"
                                        title="Data memiliki relasi dan tidak dapat dihapus"
                                        disabled>

                                    <i class="fa fa-lock"></i>

                                </button>

                            <?php else: ?>

                                <a href="?halaman=kelola_prodi&aksi=hapus&id=<?= $p->id; ?>&sesskey=<?= sesskey(); ?>"
                                   class="btn btn-danger btn-sm shadow-sm"
                                   title="Hapus Program Studi"
                                   onclick="return confirm('Apakah Anda yakin ingin menghapus program studi ini?')">

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