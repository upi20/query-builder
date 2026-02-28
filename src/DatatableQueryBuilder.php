<?php

namespace LutfiNur\QueryBuilder;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LutfiNur\QueryBuilder\Contracts\GrammarInterface;
use LutfiNur\QueryBuilder\Grammars\MySqlGrammar;
use LutfiNur\QueryBuilder\Grammars\PostgresGrammar;
use Yajra\Datatables\Datatables;

/**
 * Class DatatableQueryBuilder
 *
 * Fluent builder untuk membangun datatable query yang mendukung
 * berbagai database driver (MySQL, PostgreSQL).
 *
 * Fitur:
 * - Computed column (raw SQL expression) yang bisa di-search & di-order oleh Yajra Datatables
 * - Helper untuk DATE_FORMAT, boolean column, file link, raw column
 * - Range filter (dari-sampai), exact filter, null/not-null filter
 * - Pencarian global yang mencakup semua computed column + fillable column
 * - Parameterized query untuk mencegah SQL injection
 * - Auto-detect database driver (MySQL / PostgreSQL)
 *
 * =============================================================================================================
 * KONSEP DASAR
 * =============================================================================================================
 *
 * Setiap computed column disimpan sebagai:
 *   $columns['alias']       = 'SQL expression'
 *   $columns['alias_alias'] = 'alias'
 *
 * Lalu di-select sebagai DB::raw("SQL expression as alias").
 * Karena semua column didefinisikan sebagai raw SQL, Yajra Datatables bisa:
 *   - ORDER BY alias (sorting dari frontend bekerja)
 *   - WHERE alias LIKE '%search%' (search dari frontend bekerja)
 *
 * =============================================================================================================
 * METHODS YANG TERSEDIA
 * =============================================================================================================
 *
 * Inisialisasi:
 *   dtBuilder(string $table)                     - Buat instance DatatableQueryBuilder (via trait)
 *
 * Tambah Column:
 *   addDateColumn($column, $format, $alias)      - DATE_FORMAT / TO_CHAR column
 *   addBoolColumn($column, $true, $false, ...)   - Boolean column (_str + _class)
 *   addFileColumn($alias, $baseUrl, $src, $def)  - File link dengan IFNULL / COALESCE
 *   addConcatColumn($alias, $prefix, $column)    - CONCAT column tanpa default
 *   addAliasColumn($alias, $expression)          - Custom alias (join column)
 *   addRawColumn($alias, $expression)            - Raw SQL expression
 *
 * Filter:
 *   applyRangeFilter($model, $req, $col)         - Filter range (col_dari & col_sampai)
 *   applyExactFilters($model, $req, [...])       - Filter exact match (parameterized)
 *   applyNullFilter($model, $req, $col, $param)  - Filter NULL / NOT NULL
 *
 * Build:
 *   buildSelect()                                - Build Builder dengan select columns
 *   buildDatatable($model)                       - Build Yajra Datatable dengan search
 */
class DatatableQueryBuilder
{
    /** @var string Fully qualified model class */
    protected string $modelClass;

    /** @var string Nama tabel utama */
    protected string $table;

    /** @var array<string, string> Mapping alias => SQL expression */
    protected array $columns = [];

    /** @var array<string> List alias yang didaftarkan (untuk search & select) */
    protected array $modelFilter = [];

    /** @var array<string> Semua alias yang di-select (termasuk non-searchable) */
    protected array $selectAliases = [];

    /** @var GrammarInterface SQL grammar sesuai database driver */
    protected GrammarInterface $grammar;

    /**
     * Mapping database driver => Grammar class.
     *
     * @var array<string, class-string<GrammarInterface>>
     */
    protected static array $grammars = [
        'mysql'  => MySqlGrammar::class,
        'mariadb' => MySqlGrammar::class,
        'pgsql'  => PostgresGrammar::class,
    ];

    /**
     * @param string $modelClass Fully qualified class name model
     * @param string $table Nama tabel utama
     * @param GrammarInterface|null $grammar Grammar override (null = auto detect)
     */
    public function __construct(string $modelClass, string $table, ?GrammarInterface $grammar = null)
    {
        $this->modelClass = $modelClass;
        $this->table = $table;
        $this->grammar = $grammar ?? $this->resolveGrammar();
    }

    /**
     * Register a custom grammar for a database driver.
     *
     * @param string $driver Driver name (e.g., 'sqlsrv')
     * @param class-string<GrammarInterface> $grammarClass
     * @return void
     */
    public static function registerGrammar(string $driver, string $grammarClass): void
    {
        static::$grammars[$driver] = $grammarClass;
    }

    /**
     * Resolve grammar berdasarkan koneksi database aktif.
     *
     * Menggunakan mapping di static::$grammars.
     * Mapping bisa ditambah via registerGrammar() di AppServiceProvider.
     *
     * @return GrammarInterface
     * @throws \RuntimeException Jika driver tidak didukung
     */
    protected function resolveGrammar(): GrammarInterface
    {
        $driver = DB::connection()->getDriverName();

        if (!isset(static::$grammars[$driver])) {
            throw new \RuntimeException(
                "Database driver [{$driver}] is not supported by LutfiNur\\QueryBuilder. " .
                "Supported drivers: " . implode(', ', array_keys(static::$grammars)) . ". " .
                "You can register a custom grammar using DatatableQueryBuilder::registerGrammar()."
            );
        }

        $grammarClass = static::$grammars[$driver];
        return new $grammarClass();
    }

    // =================================================================================================================
    // COLUMN BUILDERS
    // =================================================================================================================

    /**
     * Tambahkan kolom DATE_FORMAT / TO_CHAR.
     *
     * Format string menggunakan MySQL specifiers sebagai standar:
     *   %d = day, %b = abbreviated month, %M = full month,
     *   %Y = 4-digit year, %H = 24h hour, %i = minute,
     *   %s = second, %W = full weekday name
     *
     * Untuk PostgreSQL, format otomatis dikonversi.
     *
     * @param string $column Nama kolom di tabel (e.g., 'created_at')
     * @param string $format MySQL DATE_FORMAT format string (e.g., '%d-%b-%Y')
     * @param string $alias Nama alias untuk hasil (e.g., 'created')
     * @return $this
     */
    public function addDateColumn(string $column, string $format, string $alias): static
    {
        $expression = $this->grammar->dateFormat("{$this->table}.{$column}", $format);
        $this->addRawColumn($alias, $expression);
        return $this;
    }

    /**
     * Tambahkan kolom boolean dengan _str dan _class.
     *
     * Menghasilkan 2 kolom:
     *   - {column}_str   : IF/CASE(col = 1, trueStr, falseStr)
     *   - {column}_class : IF/CASE(col = 1, trueClass, falseClass)
     *
     * @param string $column Nama kolom boolean di tabel (e.g., 'blokir')
     * @param string $trueStr Teks jika true (e.g., 'Ya')
     * @param string $falseStr Teks jika false (e.g., 'Tidak')
     * @param string $trueClass CSS class jika true (default: 'success')
     * @param string $falseClass CSS class jika false (default: 'danger')
     * @return $this
     */
    public function addBoolColumn(
        string $column,
        string $trueStr,
        string $falseStr,
        string $trueClass = 'success',
        string $falseClass = 'danger'
    ): static {
        $table = $this->table;
        $condition = "{$table}.{$column} = 1";

        $this->addRawColumn(
            "{$column}_str",
            $this->grammar->conditional($condition, "'{$trueStr}'", "'{$falseStr}'")
        );

        $this->addRawColumn(
            "{$column}_class",
            $this->grammar->conditional($condition, "'{$trueClass}'", "'{$falseClass}'")
        );

        return $this;
    }

    /**
     * Tambahkan kolom file link dengan IFNULL / COALESCE.
     *
     * Menghasilkan: IFNULL(CONCAT('baseUrl', source), 'default') as alias
     * Atau di PostgreSQL: COALESCE(CONCAT('baseUrl', source), 'default') as alias
     *
     * Cocok untuk gambar/file yang punya default jika NULL.
     *
     * @param string $alias Nama alias (e.g., 'ktp_file_link')
     * @param string $baseUrl Base URL folder (e.g., 'http://localhost/upload/peserta/')
     * @param string $sourceColumn Sumber kolom lengkap (e.g., 'peserta.ktp_file')
     * @param string $default URL default jika NULL
     * @return $this
     */
    public function addFileColumn(string $alias, string $baseUrl, string $sourceColumn, string $default): static
    {
        $concatExpr = $this->grammar->concat("'{$baseUrl}'", $sourceColumn);
        $expression = $this->grammar->ifNull($concatExpr, "'{$default}'");
        $this->addRawColumn($alias, $expression);
        return $this;
    }

    /**
     * Tambahkan kolom CONCAT tanpa default.
     *
     * Menghasilkan: CONCAT('prefix', column) as alias
     * Cocok untuk link file yang pasti ada (tidak perlu default).
     *
     * @param string $alias Nama alias (e.g., 'compro_link')
     * @param string $prefix URL prefix (e.g., 'http://localhost/upload/submit/')
     * @param string $column Kolom sumber lengkap (e.g., 'peserta.compro')
     * @return $this
     */
    public function addConcatColumn(string $alias, string $prefix, string $column): static
    {
        $expression = $this->grammar->concat("'{$prefix}'", $column);
        $this->addRawColumn($alias, $expression);
        return $this;
    }

    /**
     * Tambahkan kolom alias dari tabel lain (join).
     *
     * Sama seperti addRawColumn, hanya nama method yang lebih deskriptif.
     *
     * @param string $alias Nama alias (e.g., 'ktp_provinsi')
     * @param string $expression Ekspresi SQL (e.g., 'ktp_prov.name')
     * @return $this
     */
    public function addAliasColumn(string $alias, string $expression): static
    {
        $this->addRawColumn($alias, $expression);
        return $this;
    }

    /**
     * Tambahkan kolom raw SQL expression.
     *
     * Method dasar yang dipanggil oleh semua method addXxxColumn lainnya.
     * Mendaftarkan expression + alias ke $columns dan $modelFilter.
     *
     * ⚠️  Jika menggunakan raw expression, pastikan expression-nya sudah
     *     kompatibel dengan database driver yang digunakan, atau gunakan
     *     $this->grammar() untuk mendapatkan grammar instance.
     *
     * @param string $alias Nama alias
     * @param string $expression Raw SQL expression
     * @param bool $searchable Apakah kolom ini ikut di-search saat global search (default: true)
     * @return $this
     */
    public function addRawColumn(string $alias, string $expression, bool $searchable = true): static
    {
        $this->columns[$alias] = $expression;
        $this->columns["{$alias}_alias"] = $alias;
        $this->selectAliases[] = $alias;
        if ($searchable) {
            $this->modelFilter[] = $alias;
        }
        return $this;
    }

    // =================================================================================================================
    // FILTER HELPERS
    // =================================================================================================================

    /**
     * Terapkan range filter (dari-sampai).
     *
     * Membaca dari $request->filter['{column}_dari'] dan $request->filter['{column}_sampai'].
     *
     * @param Builder $model Query builder model
     * @param Request $request HTTP request
     * @param string $column Nama kolom (e.g., 'tanggal_lahir')
     * @param string|null $dbColumn Full column name di DB (default: table.column)
     * @return $this
     */
    public function applyRangeFilter(Builder $model, Request $request, string $column, ?string $dbColumn = null): static
    {
        $filter = $request->input('filter', []);
        $col = $dbColumn ?? "{$this->table}.{$column}";

        if (!empty($filter["{$column}_dari"])) {
            $model->where($col, '>=', $filter["{$column}_dari"]);
        }

        if (!empty($filter["{$column}_sampai"])) {
            $model->where($col, '<=', $filter["{$column}_sampai"]);
        }

        return $this;
    }

    /**
     * Terapkan exact match filter untuk beberapa kolom sekaligus.
     *
     * Menggunakan parameter binding (aman dari SQL injection).
     * Membaca dari $request->filter[$column].
     *
     * @param Builder $model Query builder model
     * @param Request $request HTTP request
     * @param array<string> $filters List nama kolom filter
     * @return $this
     */
    public function applyExactFilters(Builder $model, Request $request, array $filters): static
    {
        $filter = $request->input('filter', []);
        $table = $this->table;

        foreach ($filters as $f) {
            if (isset($filter[$f]) && $filter[$f] !== '' && $filter[$f] !== false) {
                $model->where("{$table}.{$f}", $filter[$f]);
            }
        }

        return $this;
    }

    /**
     * Terapkan filter NULL / NOT NULL.
     *
     * Jika filter[$param] == 1, maka whereNotNull.
     * Jika filter[$param] != 1, maka whereNull.
     *
     * @param Builder $model Query builder model
     * @param Request $request HTTP request
     * @param string $column Nama kolom di tabel (e.g., 'nib')
     * @param string $filterParam Nama parameter filter (e.g., 'ada_nib')
     * @return $this
     */
    public function applyNullFilter(Builder $model, Request $request, string $column, string $filterParam): static
    {
        $filter = $request->input('filter', []);
        $col = "{$this->table}.{$column}";

        if (isset($filter[$filterParam])) {
            if ($filter[$filterParam] == 1) {
                $model->whereNotNull($col);
            } else {
                $model->whereNull($col);
            }
        }

        return $this;
    }

    // =================================================================================================================
    // BUILD
    // =================================================================================================================

    /**
     * Build query SELECT dengan semua computed columns.
     *
     * Mengembalikan Eloquent Builder yang sudah di-select dengan:
     *   - table.*
     *   - Semua computed column yang sudah didaftarkan
     *
     * @return Builder
     */
    public function buildSelect(): Builder
    {
        $table = $this->table;

        $toDbRaw = array_map(function (string $alias): \Illuminate\Database\Query\Expression {
            return DB::raw($this->columns[$alias] . ' as ' . $this->columns["{$alias}_alias"]);
        }, $this->selectAliases);

        /** @var Builder $query */
        $query = ($this->modelClass)::select(array_merge([
            DB::raw("{$table}.*"),
        ], $toDbRaw));

        return $query;
    }

    /**
     * Build Yajra Datatable dari model query.
     *
     * Otomatis menangani:
     *   - addIndexColumn (DT_RowIndex)
     *   - Global search yang mencakup semua computed column + fillable column
     *   - Parameter binding pada search (aman dari SQL injection)
     *   - Driver-aware LIKE / ILIKE
     *
     * @param Builder $model Query builder yang sudah di-select & di-filter
     * @return mixed Response JSON datatable
     */
    public function buildDatatable(Builder $model): mixed
    {
        $datatable = Datatables::of($model)->addIndexColumn();

        $columns = $this->columns;
        $modelFilter = $this->modelFilter;
        $table = $this->table;
        $modelClass = $this->modelClass;
        $grammar = $this->grammar;

        $datatable->filter(function ($query) use ($columns, $modelFilter, $table, $modelClass, $grammar) {
            $search = request('search');
            $search = isset($search['value']) ? $search['value'] : null;

            if (empty($search)) {
                return;
            }

            // Kolom computed dari modelFilter + kolom fillable dari model
            $instance = new $modelClass();
            $fillableColumns = array_map(function (string $v) use ($table): string {
                return "{$table}.{$v}";
            }, $instance->getFillable());

            $searchArr = array_merge($modelFilter, $fillableColumns);

            // Build WHERE dengan parameter binding (aman dari SQL injection)
            $query->where(function ($q) use ($searchArr, $columns, $search, $grammar) {
                foreach ($searchArr as $v) {
                    $column = $columns[$v] ?? $v;
                    $likeExpr = $grammar->like($column);
                    $q->orWhereRaw("{$likeExpr}", ["%{$search}%"]);
                }
            });
        });

        return $datatable->make(true);
    }

    // =================================================================================================================
    // GETTERS
    // =================================================================================================================

    /**
     * Ambil grammar instance (untuk membuat custom expression yang driver-aware).
     *
     * @return GrammarInterface
     */
    public function grammar(): GrammarInterface
    {
        return $this->grammar;
    }

    /**
     * Ambil semua columns yang sudah didaftarkan.
     *
     * @return array<string, string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Ambil list alias model filter.
     *
     * @return array<string>
     */
    public function getModelFilter(): array
    {
        return $this->modelFilter;
    }

    /**
     * Ambil nama tabel.
     *
     * @return string
     */
    public function getTable(): string
    {
        return $this->table;
    }
}
