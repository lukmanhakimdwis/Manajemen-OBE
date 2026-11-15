# Manajemen OBE untuk Moodle

Sumber plugin berada di `obe/`. Paket pemasangan `obe.zip` dihasilkan dari sumber tersebut dengan `python3 tools/build_zip.py`; jangan mengedit ZIP secara terpisah. Paket tidak menyertakan metadata Git.

## Instalasi dan upgrade

Pasang direktori `obe` ke `local/obe` pada Moodle, lalu jalankan `php admin/cli/upgrade.php --non-interactive` dari root Moodle. Rilis 1.0.3 menggunakan versi plugin `2026111501`.

Upgrade memeriksa duplikat course pada relasi kelas dan duplikat cmid pada pemetaan aktivitas sebelum memasang unique index. Jika ditemukan duplikat, upgrade berhenti dengan pesan rekonsiliasi; data tidak dihapus otomatis. Selesaikan konflik pemetaan dan simpan riwayat akademiknya sebelum mengulang upgrade.

## Aturan laporan yang diperbaiki

- Status kompeten selalu mengikuti skala prodi, tahun akademik, dan tipe kompetensi.
- Semua tingkatan memakai jumlah kontribusi `nilai × bobot / 100`. Bobot parsial tidak dinormalisasi menjadi 100%; sisa bobot tetap terlihat pada konfigurasi.
- MK/kelas tanpa pemetaan aktivitas ke kompetensi target tidak dimasukkan sebagai nilai nol pada rata-rata lintas MK.
- Populasi laporan kelas adalah mahasiswa aktif dengan role berarchetype student, termasuk role turunan; dosen nonediting dan enrolmen suspended dikecualikan.
- Untuk mahasiswa yang mengikuti beberapa kelas MK pada tahun yang sama, kelas dengan ID course paling tinggi dipilih secara konsisten. Ini adalah aturan deterministik sementara untuk pengambilan ulang; ID terbesar tidak menjamin tanggal pengambilan terbaru. Sesuaikan aturan ini secara terpusat jika institusi memakai nilai terbaik atau tanggal percobaan akademik.
- Bobot aktivitas selalu persen 0–100, termasuk nilai 0,5% dan 1%. Tidak ada deteksi unit berdasarkan besar nilainya.
- Skala memakai batas inklusif dua desimal persis seperti konfigurasi; tidak menambahkan 0,99 ke maksimum.
- Ekspor kelas menggunakan tahun akademik relasi course, bukan session tab lain.
- Sinkronisasi menghapus kompetensi lokal yang hilang dari sumber hanya jika tidak dirujuk. Jika masih dirujuk bobot/aktivitas, transaksi dibatalkan agar riwayat tidak rusak. Arsipkan kurikulum historis atau selesaikan referensinya sebelum sinkronisasi.
- Master yang masih dirujuk tidak dapat dihapus melalui service penyimpanan. Pengaturan ambang lama mengarahkan pengguna ke skala nilai melalui pesan migrasi yang eksplisit.

## Perbaikan keamanan

Ekspor membutuhkan capability laporan dan scope prodi yang sah; ekspor individual pada endpoint administratif tidak tersedia untuk akun mahasiswa biasa. Penghapusan mapping memeriksa course sebenarnya dari module. Mutasi master MK/angkatan membutuhkan capability tulis dan pemeriksaan record lama serta prodi tujuan. Nama dinamis tidak lagi dimasukkan ke handler konfirmasi JavaScript. Laporan untuk pengguna tanpa hak melihat nilai hidden ditolak jika melibatkan nilai hidden, sehingga nilai juga tidak bocor lewat agregat.

## Pengujian

Dari root Moodle yang telah diinisialisasi untuk PHPUnit:

```sh
php -d max_input_vars=5000 -d memory_limit=512M vendor/bin/phpunit --testsuite local_obe_testsuite
```

`tests/fixes_test.php` berisi regresi untuk temuan audit. Tes lama disesuaikan dengan autentikasi mutasi, exception yang benar, input array untuk tambah relasi massal, dan fixture course/module nyata. Validasi dilakukan pada Moodle 4.5.16, PHP 8.3.36, PostgreSQL 16.16; matriks seluruh versi Moodle sejak minimum 4.1 belum dijalankan.

Hasil validasi rilis: 97 tes, 226 assertions, seluruhnya lulus; lint seluruh PHP lulus; upgrade CLI berhasil dan kedua unique index terverifikasi. Pengujian HTTP lokal juga memastikan tiga ekspor administratif menolak akun biasa, mutasi lintas prodi tidak mengubah record, mapping course lain tetap ada, nilai hidden ditolak, dan input nama berisi ekspresi JavaScript tidak masuk ke event handler. Login admin serta dashboard tetap berhasil. Seluruh pengujian menggunakan data buatan yang dibersihkan setelah pemeriksaan.
