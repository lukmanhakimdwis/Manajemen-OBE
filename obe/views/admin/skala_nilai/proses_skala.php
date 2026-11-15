<?php
// File: local/obe/views/admin/skala_nilai/proses_skala.php
require_once(__DIR__ . '/../../../../../config.php');

require_login();
\local_obe\access_guard::require_admin_or_kaprodi_capability('local/obe:configprogramscale');
require_sesskey();

use local_obe\manager\skala_nilai_manager;
use local_obe\manager\prodi_manager;

$aksi = optional_param('aksi', '', PARAM_ALPHA);
$id_prodi = 0;

// Identifikasi peran pengguna aktif
$prodi_aktif = prodi_manager::get_prodi_aktif_user();

// ====================================================
// 1. PROSES HAPUS DATA (ANTI-IDOR)
// ====================================================
if ($aksi === 'hapus') {
    $id = required_param('id', PARAM_INT);
    $id_prodi = optional_param('prodi', 0, PARAM_INT);

    // Validasi Keamanan Server: Periksa kepemilikan data sebelum menghapus
    if ($prodi_aktif) {
        $data_lama = skala_nilai_manager::ambil($id);
        if (!$data_lama) {
            throw new \moodle_exception('invalidrecord', 'error');
        }

        if ((int)$data_lama->id_prodi !== (int)$prodi_aktif->id) {
            throw new \moodle_exception(
                'aksesditolak',
                'local_obe',
                '',
                null,
                'Aksi ditolak! Anda tidak memiliki hak akses untuk menghapus skala nilai milik program studi lain.'
            );
        }
        // Paksa redirect kembali ke prodi asli Kaprodi
        $id_prodi = (int)$prodi_aktif->id;
    }

    skala_nilai_manager::hapus($id);

    redirect(
        new moodle_url('/local/obe/views/admin/dashboard.php', [
            'halaman' => 'skala_nilai',
            'prodi'   => $id_prodi
        ]),
        'Skala nilai berhasil dihapus.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// ====================================================
// 2. PROSES TAMBAH / EDIT DATA (ANTI-IDOR)
// ====================================================
if ($formdata = data_submitted()) {
    $data = new stdClass();
    $data->id                = clean_param($formdata->id ?? 0, PARAM_INT);
    $data->id_prodi          = clean_param($formdata->id_prodi ?? 0, PARAM_INT);
    $data->id_tahun_akademik = clean_param($formdata->id_tahun_akademik ?? 0, PARAM_INT);
    $data->tipe_kompetensi   = clean_param($formdata->tipe_kompetensi ?? '', PARAM_TEXT);
    $data->huruf_nilai       = clean_param($formdata->huruf_nilai ?? '', PARAM_TEXT);
    $data->nilai_min         = clean_param($formdata->nilai_min ?? 0, PARAM_FLOAT);
    $data->nilai_max         = clean_param($formdata->nilai_max ?? 0, PARAM_FLOAT);
    $data->status_kompeten   = clean_param($formdata->status_kompeten ?? 0, PARAM_INT);

    // Validasi Keamanan Server: Periksa kepemilikan data saat proses simpan/update
    if ($prodi_aktif) {
        // Paksa data prodi selalu menggunakan ID prodi naungan asli milik Kaprodi
        $data->id_prodi = (int)$prodi_aktif->id;

        // Jika dalam mode edit (update), pastikan data yang diincar benar milik prodinya
        if (!empty($data->id)) {
            $lama = skala_nilai_manager::ambil($data->id);
            if (!$lama) {
                throw new \moodle_exception('invalidrecord', 'error');
            }

            if ((int)$lama->id_prodi !== (int)$prodi_aktif->id) {
                throw new \moodle_exception(
                    'aksesditolak',
                    'local_obe',
                    '',
                    null,
                    'Aksi ditolak! Anda tidak memiliki hak akses untuk mengubah konfigurasi skala nilai milik program studi lain.'
                );
            }
        }
    }

    // Perbarui variabel tracker untuk tujuan redirect pasca-sukses
    $id_prodi = $data->id_prodi;

    // URL fallback form jika terjadi kegagalan validasi logika kurikulum
    $url_form = new moodle_url('/local/obe/views/admin/dashboard.php', [
        'halaman' => 'skala_nilai',
        'sub'     => 'form',
        'prodi'   => $id_prodi,
        'tipe'    => $data->tipe_kompetensi
    ]);

    if (!empty($data->id)) {
        // Jika mode edit, tambahkan parameter ID agar form tidak mereset menjadi mode tambah baru
        $url_form->param('id', $data->id);
    }

    if ($data->id_prodi <= 0 || $data->id_tahun_akademik <= 0) {
        redirect(
            $url_form,
            'Gagal menyimpan! Program studi dan tahun akademik wajib dipilih.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    if ($data->nilai_min < 0 || $data->nilai_max > 100) {
        redirect(
            $url_form,
            'Gagal menyimpan! Rentang nilai harus berada di antara 0 sampai 100.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Validasi Logika Rentang Nilai Kurikulum
    if ($data->nilai_min >= $data->nilai_max) {
        redirect(
            $url_form,
            'Gagal menyimpan! Ambang nilai minimum tidak boleh lebih besar atau sama dengan nilai maksimum.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Validasi Tumpang Tindih Batas (Overlap Check)
    $tabrakan = skala_nilai_manager::apakah_rentang_tabrakan(
        $data->id_prodi,
        $data->id_tahun_akademik,
        $data->tipe_kompetensi,
        $data->nilai_min,
        $data->nilai_max,
        $data->id
    );

    if ($tabrakan) {
        redirect(
            $url_form,
            'Gagal menyimpan! Batas rentang nilai yang Anda masukkan bertabrakan dengan data huruf mutu yang sudah ada sebelumnya.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Operasi Eksekusi Database
    if (empty($data->id)) {
        $newid = skala_nilai_manager::tambah($data);
        $data->id = $newid;
    } else {
        skala_nilai_manager::update($data);
    }

    // Sinkronisasi otomatis ambang batas kelulusan kompeten ( OBE Anchor Rule )
   skala_nilai_manager::set_ambang_kompeten(
    $data->id_prodi,
    $data->id_tahun_akademik,
    $data->tipe_kompetensi,
    $data->nilai_min,
    $data->status_kompeten
);
}

// ====================================================
// 3. REDIRECT UTAMA (OPERASI SUKSES)
// ====================================================
redirect(
    new moodle_url('/local/obe/views/admin/dashboard.php', [
        'halaman' => 'skala_nilai',
        'prodi'   => $id_prodi
    ]),
    'Konfigurasi matriks skala nilai kurikulum berhasil disimpan.',
    null,
    \core\output\notification::NOTIFY_SUCCESS
);