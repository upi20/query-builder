<?php

namespace LutfiNur\QueryBuilder\Traits;

use LutfiNur\QueryBuilder\Contracts\GrammarInterface;
use LutfiNur\QueryBuilder\DatatableQueryBuilder;

/**
 * Trait HasDatatableQuery
 *
 * Gunakan trait ini di Model Eloquent untuk mendapatkan akses ke DatatableQueryBuilder.
 *
 * Requirement di Model:
 *   - const tableName = 'nama_table';
 *   - protected $fillable = [...];
 *
 * Contoh penggunaan:
 *
 *   use LutfiNur\QueryBuilder\Traits\HasDatatableQuery;
 *
 *   class Peserta extends Model
 *   {
 *       use HasDatatableQuery;
 *
 *       const tableName = 'peserta';
 *       protected $fillable = ['nama', 'email', ...];
 *
 *       public static function datatable(Request $request): mixed
 *       {
 *           $dt = static::dtBuilder(static::tableName);
 *
 *           $dt->addDateColumn('created_at', '%d-%b-%Y', 'created');
 *           $dt->addBoolColumn('aktif', 'Ya', 'Tidak');
 *
 *           $model = $dt->buildSelect();
 *           return $dt->buildDatatable($model);
 *       }
 *   }
 */
trait HasDatatableQuery
{
    /**
     * Buat instance DatatableQueryBuilder baru.
     *
     * @param string $table Nama tabel utama (biasanya static::tableName)
     * @param GrammarInterface|null $grammar Grammar override (null = auto detect dari DB connection)
     * @return DatatableQueryBuilder
     */
    public static function dtBuilder(string $table, ?GrammarInterface $grammar = null): DatatableQueryBuilder
    {
        return new DatatableQueryBuilder(static::class, $table, $grammar);
    }
}
