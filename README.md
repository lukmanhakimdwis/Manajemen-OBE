# Manajemen OBE dan Wawancara AI untuk Moodle

Sumber terbaru pilot OBE berbahasa Indonesia:

| Plugin | Sumber | Paket | Lokasi pemasangan Moodle |
|---|---|---|---|
| Manajemen OBE (`local_obe`, 1.1.0-ai-pilot) | `obe/` | `obe.zip` | `local/obe` |
| Wawancara AI (`mod_obeinterview`, 0.1.0-pilot) | `obeinterview/` | `obeinterview.zip` | `mod/obeinterview` |

Keduanya berstatus pilot/alpha, versi internal `2026111502`. Wawancara AI bergantung pada `local_obe`. Runtime pengujian staging: Moodle 4.5.16, PHP 8.3.36, PostgreSQL 16.16. Dukungan seluruh versi sejak minimum deklaratif Moodle 4.1 belum diuji.

## Fitur dan integritas penilaian

- Struktur CPL → Indikator Ketercapaian CPL → CPMK → Sub-CPMK → aktivitas/bukti, dengan bobot persen dan penelusuran bukti.
- Penghapusan/perubahan biasa aspek yang sudah memiliki nilai, termasuk nol, atau riwayat keputusan ditolak. Penggantian mempertahankan jejak historis dan mencegah bobot aktif ganda. Batas jalur penghapusan bawaan Moodle tetap perlu diperhatikan sesuai implementasi plugin.
- Wawancara teks menyimpan giliran, kutipan per kriteria, proposal, dan keputusan dosen. Nol berbeda dari bukti belum cukup (`null`). Proposal terbaru tidak menimpa keputusan lama atau Gradebook sebelum disahkan.
- Penyedia simulasi memilih kutipan sesuai kriteria; integrasi Codex nyata tidak dinyatakan terverifikasi oleh rilis ini. Akses provider resmi, kredensial backend, dan izin penggunaan harus dikonfigurasi institusi.
- Scope/capability membatasi akses mahasiswa, dosen, dan pengelola. Lab Pemrograman masih ditunda.

## Instalasi dan upgrade

Backup database dan moodledata sebelum upgrade. Pasang kedua direktori plugin pada lokasi di atas, lalu dari root Moodle:

```sh
php admin/cli/upgrade.php --non-interactive
php admin/cli/purge_caches.php
```

Upgrade memeriksa duplikat pemetaan sebelum unique index. Konflik harus direkonsiliasi dengan mempertahankan riwayat; jangan menghapus nilai atau melakukan pembersihan massal untuk melewati pemeriksaan. Konfigurasi kurikulum, skala, bobot dan provider dilakukan lewat plugin. RPS, CPL dan indikator institusi harus diverifikasi; data/ambang demo tidak menjadi kebijakan akademik resmi.

Bangun ulang kedua ZIP setelah perubahan sumber:

```sh
python3 tools/build_zip.py
```

Paket tidak memuat kredensial, database, moodledata, konfigurasi rahasia, atau log staging. Catatan versi sebelumnya tersimpan di [docs/README-1.0.3.md](docs/README-1.0.3.md).

## Status pengujian, 18 November 2026

- 153 tes lokal Python pada workspace staging lulus; hasil ini bukan PHPUnit Moodle.
- Runtime terakhir yang dilaporkan operator: 34 dari 35 kelompok PHPUnit, seluruh 4 fixture PHP, dan verifikasi dataset lulus.
- Satu kegagalan tersisa pada fixture form tambah/edit telah dipatch: constructor menerima nomor section, ID record section dipakai hanya untuk lookup, dan global COURSE diselaraskan dengan PAGE. Assertion PAGE, hak akses, serta callback penyimpanan dipertahankan.
- Lint PHP dan PHPUnit Moodle untuk patch form terakhir belum dijalankan ulang karena akses Docker sesi pengembangan diblokir. Rilis ini tidak mengklaim 35/35 kelompok telah lulus.

Contoh eksekusi kelompok terdampak setelah inisialisasi PHPUnit Moodle pada database pengujian terpisah:

```sh
php -d max_input_vars=5000 -d memory_limit=512M vendor/bin/phpunit local/obe/tests/ai_mod_form_test.php
```

Jalankan juga seluruh tes `local/obe/tests` dan `mod/obeinterview/tests` serta pemeriksaan Gradebook/OBE sebelum pemakaian produksi. Repository ini memuat sumber dan paket plugin; orkestrasi VPS, dataset RPS, worker, dan rahasia deployment tidak disertakan dalam pembaruan plugin ini.
