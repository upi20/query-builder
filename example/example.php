<?php

/**
 * =============================================================================================================
 * CONTOH PENGGUNAAN: LutfiNur\QueryBuilder
 * =============================================================================================================
 *
 * File ini adalah contoh penggunaan package lutfi-nur/query-builder
 * di dalam model Laravel.
 *
 * Trait HasDatatableQuery menyediakan method dtBuilder() untuk
 * membuat DatatableQueryBuilder yang otomatis mendeteksi
 * database driver (MySQL / PostgreSQL).
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LutfiNur\QueryBuilder\Traits\HasDatatableQuery;

class Peserta extends Model
{
    use HasDatatableQuery;

    const tableName = 'peserta';
    const image_folder = '/upload/peserta';
    const image_default = 'upload/peserta/default.png';

    protected $fillable = [
        'nama', 'email', 'nopeserta', 'tanggal_lahir', 'usia_saat_daftar',
        'jenis_kelamin', 'agama', 'pendidikan_terakhir', 'blokir',
        'edit_data', 'edit_data_nik', 'domisili', 'nib',
        'ktp_provinsi_id', 'ktp_kab_kot_id', 'ktp_kecamatan_id', 'ktp_des_kel_id',
        'domisili_provinsi_id', 'domisili_kab_kot_id', 'domisili_kecamatan_id', 'domisili_des_kel_id',
        'ktp_file', 'kk_file', 'domisili_file', 'produk',
        'compro', 'cv', 'user_id', 'kurasi_pernah_lulus',
    ];

    public static function datatable(Request $request): mixed
    {
        // Locale setting (MySQL only — skip jika PostgreSQL)
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("SET lc_time_names = 'id_ID'");
        }

        $table = static::tableName;
        $t_prov = Province::tableName;
        $t_kab = Regencie::tableName;
        $t_kec = District::tableName;
        $t_des = Village::tableName;
        $t_user = User::tableName;

        $user_image_folder = url(User::image_folder) . '/';
        $user_image_private_folder = loader_peserta('') . '/';
        $user_image_default = url(User::image_default);
        $min_usia = setting_get('latsar.pendaftaran.usia.min', 16);
        $max_usia = setting_get('latsar.pendaftaran.usia.max', 30);
        $base_url_image_folder = url(str_replace('./', '', static::image_folder)) . '/';
        $base_url_submit_folder = url(str_replace('./', '', PesertaSubmit::file_folder)) . '/';
        $image_default = url(static::image_default);

        // === Inisialisasi builder (auto-detect MySQL / PostgreSQL) ===
        $dt = static::dtBuilder($table);

        // === Date columns ===
        // Format menggunakan MySQL specifier, otomatis dikonversi untuk PostgreSQL
        $dt->addDateColumn('created_at', '%d-%b-%Y', 'created');
        $dt->addDateColumn('created_at', '%d-%M-%Y %H:%i:%s', 'created_str');
        $dt->addDateColumn('updated_at', '%d-%b-%Y', 'updated');
        $dt->addDateColumn('updated_at', '%W, %d %M %Y %H:%i:%s', 'updated_str');
        $dt->addDateColumn('tanggal_lahir', '%d-%b-%Y', 'tanggal_lahir_str');

        // === File link columns (CONCAT tanpa default) ===
        $dt->addConcatColumn('compro_link', $base_url_submit_folder, "$table.compro");
        $dt->addConcatColumn('cv_link', $base_url_submit_folder, "$table.cv");

        // === File link columns (IFNULL/COALESCE dengan default) ===
        $dt->addFileColumn('foto_profile_link', $user_image_folder, "$t_user.foto", $user_image_default);
        $dt->addFileColumn('ktp_file_link', $user_image_private_folder, "$table.ktp_file", $image_default);
        $dt->addFileColumn('kk_file_link', $user_image_private_folder, "$table.kk_file", $image_default);
        $dt->addFileColumn('domisili_file_link', $user_image_private_folder, "$table.domisili_file", $image_default);
        $dt->addFileColumn('produk_link', $base_url_image_folder, "$table.produk", $image_default);

        // === Boolean columns (otomatis _str + _class) ===
        $dt->addBoolColumn('ktp_ada', 'Ada', 'Tidak Ada', 'text-success', 'text-danger');
        $dt->addBoolColumn('kurasi_pernah_lulus', 'Pernah', 'Belum');
        $dt->addBoolColumn('blokir', 'Ya', 'Tidak', 'danger', 'success');
        $dt->addBoolColumn('edit_data', 'Ya', 'Tidak');
        $dt->addBoolColumn('edit_data_nik', 'Ya', 'Tidak');

        // === Custom raw expressions ===
        // Untuk raw expression yang menggunakan IF/CASE, gunakan grammar() agar driver-aware:
        $grammar = $dt->grammar();

        $dt->addRawColumn('domisili_str',
            $grammar->conditional("$table.domisili = 1", "'Kota Bandung'", "'Luar Kota Bandung'")
        );

        // Nested conditional (raw expression — pastikan kompatibel atau gunakan grammar)
        $dt->addRawColumn('domisili_class',
            $grammar->conditional(
                "$table.domisili = 1",
                "'text-success'",
                $grammar->conditional("$table.domisili_file IS NULL", "'text-danger'", "'text-warning'")
            )
        );

        $dt->addRawColumn('tanggal_lahir_class',
            $grammar->conditional(
                "$table.usia_saat_daftar >= $min_usia AND $table.usia_saat_daftar <= $max_usia",
                "'text-success'",
                "'text-danger'"
            )
        );

        // === Alias columns (dari joined tables) ===
        $dt->addAliasColumn('ktp_provinsi', 'ktp_prov.name');
        $dt->addAliasColumn('ktp_kab_kot', 'ktp_kab.name');
        $dt->addAliasColumn('ktp_kecamatan', 'ktp_kec.name');
        $dt->addAliasColumn('ktp_des_kel', 'ktp_des.name');
        $dt->addAliasColumn('domisili_provinsi', 'domisili_prov.name');
        $dt->addAliasColumn('domisili_kab_kot', 'domisili_kab.name');
        $dt->addAliasColumn('domisili_kecamatan', 'domisili_kec.name');
        $dt->addAliasColumn('domisili_des_kel', 'domisili_des.name');

        // === Build Select dengan Join ===
        $model = $dt->buildSelect()
            ->leftJoin("$t_prov as ktp_prov", "$table.ktp_provinsi_id", "=", "ktp_prov.id")
            ->leftJoin("$t_kab as ktp_kab", "$table.ktp_kab_kot_id", "=", "ktp_kab.id")
            ->leftJoin("$t_kec as ktp_kec", "$table.ktp_kecamatan_id", "=", "ktp_kec.id")
            ->leftJoin("$t_des as ktp_des", "$table.ktp_des_kel_id", "=", "ktp_des.id")
            ->leftJoin("$t_prov as domisili_prov", "$table.domisili_provinsi_id", "=", "domisili_prov.id")
            ->leftJoin("$t_kab as domisili_kab", "$table.domisili_kab_kot_id", "=", "domisili_kab.id")
            ->leftJoin("$t_kec as domisili_kec", "$table.domisili_kecamatan_id", "=", "domisili_kec.id")
            ->leftJoin("$t_des as domisili_des", "$table.domisili_des_kel_id", "=", "domisili_des.id")
            ->leftJoin($t_user, "$table.user_id", "=", "$t_user.id");

        // === Range filters ===
        $dt->applyRangeFilter($model, $request, 'tanggal_lahir');
        $dt->applyRangeFilter($model, $request, 'usia_saat_daftar');
        $dt->applyRangeFilter($model, $request, 'jml_daftar');

        // === Null filters ===
        $dt->applyNullFilter($model, $request, 'nib', 'ada_nib');
        $dt->applyNullFilter($model, $request, 'compro', 'ada_compro');

        // === Exact match filters (parameterized — aman dari SQL injection) ===
        $dt->applyExactFilters($model, $request, [
            'ktp_provinsi_id',
            'ktp_kab_kot_id',
            'ktp_kecamatan_id',
            'ktp_des_kel_id',
            'domisili',
            'domisili_provinsi_id',
            'domisili_kab_kot_id',
            'domisili_kecamatan_id',
            'domisili_des_kel_id',
            'kurasi_pernah_lulus',
            'blokir',
            'edit_data',
            'edit_data_nik',
            'jenis_kelamin',
            'agama',
            'pendidikan_terakhir',
        ]);

        // === Kondisi tambahan ===
        $model->whereNotNull('nopeserta');

        // === Build & return datatable ===
        return $dt->buildDatatable($model);
    }
}
