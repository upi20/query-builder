# LutfiNur/QueryBuilder

Fluent datatable query builder untuk Laravel dengan [Yajra Datatables](https://github.com/yajra/laravel-datatables). Mendukung **MySQL** dan **PostgreSQL**.

## Requirements

- PHP >= 8.1
- Laravel >= 10.0
- [yajra/laravel-datatables-oracle](https://github.com/yajra/laravel-datatables) >= 10.0

## Installation

```bash
composer require lutfi-nur/query-builder
```

Tidak perlu publish config atau register service provider.
Package ini **zero overhead** — class hanya di-load saat `dtBuilder()` dipanggil.

## Quick Start

### 1. Tambahkan Trait ke Model

```php
use LutfiNur\QueryBuilder\Traits\HasDatatableQuery;

class Peserta extends Model
{
    use HasDatatableQuery;

    const tableName = 'peserta';
    protected $fillable = ['nama', 'email', 'tanggal_lahir', 'blokir'];
}
```

### 2. Buat Method Datatable

```php
use Illuminate\Http\Request;

class Peserta extends Model
{
    use HasDatatableQuery;

    const tableName = 'peserta';
    protected $fillable = ['nama', 'email', 'tanggal_lahir', 'blokir'];

    public static function datatable(Request $request): mixed
    {
        $table = static::tableName;
        $dt = static::dtBuilder($table);

        // Date columns
        $dt->addDateColumn('created_at', '%d-%b-%Y', 'created');
        $dt->addDateColumn('updated_at', '%W, %d %M %Y %H:%i:%s', 'updated_str');

        // Boolean columns (generates _str + _class)
        $dt->addBoolColumn('blokir', 'Ya', 'Tidak', 'danger', 'success');

        // File link columns
        $dt->addFileColumn('foto_link', url('upload/foto') . '/', "$table.foto", url('img/default.png'));
        $dt->addConcatColumn('doc_link', url('upload/doc') . '/', "$table.document");

        // Alias from joined table
        $dt->addAliasColumn('provinsi_name', 'prov.name');

        // Raw expression
        $dt->addRawColumn('status_class', "(CASE WHEN $table.status = 1 THEN 'success' ELSE 'danger' END)");

        // Build select with joins
        $model = $dt->buildSelect()
            ->leftJoin('provinces as prov', "$table.provinsi_id", '=', 'prov.id');

        // Apply filters
        $dt->applyRangeFilter($model, $request, 'tanggal_lahir');
        $dt->applyExactFilters($model, $request, ['provinsi_id', 'blokir']);
        $dt->applyNullFilter($model, $request, 'email', 'ada_email');

        // Build & return
        return $dt->buildDatatable($model);
    }
}
```

### 3. Route

```php
Route::get('/peserta/datatable', function (Request $request) {
    return Peserta::datatable($request);
});
```

## API Reference

### Column Builders

| Method | Deskripsi |
|--------|-----------|
| `addDateColumn($column, $format, $alias)` | Format tanggal (`DATE_FORMAT` / `TO_CHAR`) |
| `addBoolColumn($column, $true, $false, $trueClass, $falseClass)` | Boolean → `{col}_str` + `{col}_class` |
| `addFileColumn($alias, $baseUrl, $source, $default)` | File link dengan fallback (`IFNULL` / `COALESCE`) |
| `addConcatColumn($alias, $prefix, $column)` | CONCAT tanpa default |
| `addAliasColumn($alias, $expression)` | Alias dari joined table |
| `addRawColumn($alias, $expression)` | Raw SQL expression |

### Filters

| Method | Deskripsi |
|--------|-----------|
| `applyRangeFilter($model, $request, $column, $dbColumn?)` | Range filter (`{col}_dari` & `{col}_sampai`) |
| `applyExactFilters($model, $request, $columns)` | Exact match filter (parameterized) |
| `applyNullFilter($model, $request, $column, $filterParam)` | NULL / NOT NULL filter |

### Build

| Method | Deskripsi |
|--------|-----------|
| `buildSelect()` | Build Eloquent Builder with all computed columns |
| `buildDatatable($model)` | Build Yajra Datatable response with global search |

### Getters

| Method | Deskripsi |
|--------|-----------|
| `grammar()` | Get current grammar instance |
| `getColumns()` | Get all registered columns |
| `getModelFilter()` | Get all registered alias list |
| `getTable()` | Get table name |

## Date Format

Format string menggunakan MySQL specifiers sebagai standar. Untuk PostgreSQL, konversi dilakukan otomatis.

| MySQL | PostgreSQL | Keterangan |
|-------|-----------|------------|
| `%Y` | `YYYY` | Year (4-digit) |
| `%y` | `YY` | Year (2-digit) |
| `%m` | `MM` | Month (01-12) |
| `%d` | `DD` | Day (01-31) |
| `%H` | `HH24` | Hour (00-23) |
| `%i` | `MI` | Minute (00-59) |
| `%s` | `SS` | Second (00-59) |
| `%M` | `FMMonth` | Full month name |
| `%b` | `Mon` | Abbreviated month |
| `%W` | `FMDay` | Full weekday name |
| `%a` | `Dy` | Abbreviated weekday |

## Database Support

| Driver | Status | Grammar Class |
|--------|--------|---------------|
| MySQL | ✅ Supported | `MySqlGrammar` |
| MariaDB | ✅ Supported | `MySqlGrammar` |
| PostgreSQL | ✅ Supported | `PostgresGrammar` |

Database driver otomatis terdeteksi dari koneksi Laravel yang aktif.

### Custom Grammar

Untuk mendukung driver lain (misal SQL Server), buat class yang implement `GrammarInterface`
dan register di `AppServiceProvider::boot()`:

```php
use LutfiNur\QueryBuilder\Contracts\GrammarInterface;
use LutfiNur\QueryBuilder\DatatableQueryBuilder;

// 1. Buat grammar class
class SqlServerGrammar implements GrammarInterface
{
    // implement all methods...
}

// 2. Register di AppServiceProvider::boot()
DatatableQueryBuilder::registerGrammar('sqlsrv', SqlServerGrammar::class);
```

## SQL yang Dihasilkan

### MySQL
```sql
SELECT peserta.*,
       (DATE_FORMAT(peserta.created_at, '%d-%b-%Y')) as created,
       (IF(peserta.blokir = 1, 'Ya', 'Tidak')) as blokir_str,
       IFNULL(CONCAT('http://localhost/upload/', peserta.foto), 'http://localhost/img/default.png') as foto_link
FROM peserta
WHERE blokir_str LIKE '%search%'
```

### PostgreSQL
```sql
SELECT peserta.*,
       (TO_CHAR(peserta.created_at, 'DD-Mon-YYYY')) as created,
       (CASE WHEN peserta.blokir = 1 THEN 'Ya' ELSE 'Tidak' END) as blokir_str,
       COALESCE(CONCAT('http://localhost/upload/', peserta.foto), 'http://localhost/img/default.png') as foto_link
FROM peserta
WHERE blokir_str::text ILIKE '%search%'
```

## Perbedaan dengan Versi Sebelumnya

| Sebelumnya | Sekarang |
|------------|----------|
| Hardcoded MySQL (`IF`, `IFNULL`, `DATE_FORMAT`) | Grammar abstraction |
| `$instance->fillable` (akses property langsung) | `$instance->getFillable()` |
| `$request->filter` (akses property) | `$request->input('filter', [])` |
| Single file, no namespace | PSR-4 package structure |
| `return false` di filter callback | `return` (void) |
| ServiceProvider berat | No ServiceProvider (zero overhead) |

## License

MIT
