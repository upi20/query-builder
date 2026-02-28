# LutfiNur/QueryBuilder

A fluent datatable query builder for Laravel with [Yajra Datatables](https://github.com/yajra/laravel-datatables). Supports **MySQL** and **PostgreSQL** with automatic driver detection.

## Requirements

- PHP >= 8.1
- Laravel >= 10.0
- [yajra/laravel-datatables-oracle](https://github.com/yajra/laravel-datatables) >= 10.0

## Installation

```bash
composer require lutfi-nur/query-builder
```

No service provider registration or config publishing required.
This package is **zero overhead** — classes are only loaded when `dtBuilder()` is called.

## Quick Start

### 1. Add the Trait to Your Model

```php
use LutfiNur\QueryBuilder\Traits\HasDatatableQuery;

class User extends Model
{
    use HasDatatableQuery;

    const tableName = 'users';
    protected $fillable = ['name', 'email', 'birth_date', 'is_blocked'];
}
```

### 2. Create a Datatable Method

```php
use Illuminate\Http\Request;

class User extends Model
{
    use HasDatatableQuery;

    const tableName = 'users';
    protected $fillable = ['name', 'email', 'birth_date', 'is_blocked'];

    public static function datatable(Request $request): mixed
    {
        $table = static::tableName;
        $dt = static::dtBuilder($table);

        // Date columns (auto-converted for PostgreSQL)
        $dt->addDateColumn('created_at', '%d-%b-%Y', 'created');
        $dt->addDateColumn('updated_at', '%W, %d %M %Y %H:%i:%s', 'updated_str');

        // Boolean columns (generates {col}_str + {col}_class)
        $dt->addBoolColumn('is_blocked', 'Yes', 'No', 'danger', 'success');

        // File link with fallback (IFNULL / COALESCE)
        $dt->addFileColumn('avatar_link', url('upload/avatar') . '/', "$table.avatar", url('img/default.png'));

        // CONCAT without fallback
        $dt->addConcatColumn('doc_link', url('upload/doc') . '/', "$table.document");

        // Alias from joined table
        $dt->addAliasColumn('province_name', 'prov.name');

        // Raw expression (driver-aware via grammar)
        $grammar = $dt->grammar();
        $dt->addRawColumn('status_label', $grammar->conditional("$table.status = 1", "'Active'", "'Inactive'"));

        // Select-only column (not included in global search)
        $dt->addRawColumn('status_class', $grammar->conditional("$table.status = 1", "'success'", "'danger'"), searchable: false);

        // Build select with joins
        $model = $dt->buildSelect()
            ->leftJoin('provinces as prov', "$table.province_id", '=', 'prov.id');

        // Apply filters
        $dt->applyRangeFilter($model, $request, 'birth_date');
        $dt->applyExactFilters($model, $request, ['province_id', 'is_blocked']);
        $dt->applyNullFilter($model, $request, 'email', 'has_email');

        // Build & return datatable response
        return $dt->buildDatatable($model);
    }
}
```

### 3. Define a Route

```php
Route::get('/users/datatable', function (Request $request) {
    return User::datatable($request);
});
```

## API Reference

### Column Builders

| Method | Description |
|--------|-------------|
| `addDateColumn($column, $format, $alias)` | Date format (`DATE_FORMAT` / `TO_CHAR`) |
| `addBoolColumn($column, $true, $false, $trueClass, $falseClass)` | Boolean column → generates `{col}_str` + `{col}_class` |
| `addFileColumn($alias, $baseUrl, $source, $default)` | File link with NULL fallback (`IFNULL` / `COALESCE`) |
| `addConcatColumn($alias, $prefix, $column)` | CONCAT without fallback |
| `addAliasColumn($alias, $expression)` | Alias from a joined table |
| `addRawColumn($alias, $expression, $searchable)` | Raw SQL expression. Set `$searchable = false` to exclude from global search |

### Filters

All filters read from `$request->input('filter')`.

| Method | Description |
|--------|-------------|
| `applyRangeFilter($model, $request, $column, $dbColumn?)` | Range filter using `filter[{col}_dari]` and `filter[{col}_sampai]` |
| `applyExactFilters($model, $request, $columns)` | Exact match filter (parameterized, SQL injection safe) |
| `applyNullFilter($model, $request, $column, $filterParam)` | NULL / NOT NULL filter. `filter[$param] == 1` → `whereNotNull`, otherwise `whereNull` |

### Build

| Method | Description |
|--------|-------------|
| `buildSelect()` | Returns an Eloquent Builder with `table.*` + all computed columns |
| `buildDatatable($model)` | Returns a Yajra Datatable JSON response with global search |

### Getters

| Method | Description |
|--------|-------------|
| `grammar()` | Get the current `GrammarInterface` instance for building driver-aware expressions |
| `getColumns()` | Get all registered column expressions |
| `getModelFilter()` | Get the list of searchable aliases |
| `getTable()` | Get the table name |

## Date Format

Format strings use MySQL specifiers as the standard. For PostgreSQL, conversion is automatic.

| MySQL | PostgreSQL | Description |
|-------|-----------|-------------|
| `%Y` | `YYYY` | Year (4-digit) |
| `%y` | `YY` | Year (2-digit) |
| `%m` | `MM` | Month (01-12) |
| `%d` | `DD` | Day (01-31) |
| `%e` | `FMDD` | Day (1-31, no leading zero) |
| `%H` | `HH24` | Hour (00-23) |
| `%h` | `HH12` | Hour (01-12) |
| `%i` | `MI` | Minute (00-59) |
| `%s` | `SS` | Second (00-59) |
| `%M` | `FMMonth` | Full month name |
| `%b` | `Mon` | Abbreviated month |
| `%W` | `FMDay` | Full weekday name |
| `%a` | `Dy` | Abbreviated weekday |
| `%p` | `AM` | AM/PM |

## Database Support

| Driver | Status | Grammar Class |
|--------|--------|---------------|
| MySQL | Supported | `MySqlGrammar` |
| MariaDB | Supported | `MySqlGrammar` |
| PostgreSQL | Supported | `PostgresGrammar` |

The database driver is automatically detected from the active Laravel database connection.

### Custom Grammar

To support additional drivers (e.g., SQL Server), create a class that implements `GrammarInterface` and register it in your `AppServiceProvider::boot()`:

```php
use LutfiNur\QueryBuilder\Contracts\GrammarInterface;
use LutfiNur\QueryBuilder\DatatableQueryBuilder;

class SqlServerGrammar implements GrammarInterface
{
    // Implement all methods: dateFormat, conditional, ifNull, concat, like, getDriverName
}

// In AppServiceProvider::boot()
DatatableQueryBuilder::registerGrammar('sqlsrv', SqlServerGrammar::class);
```

## Generated SQL Examples

### MySQL
```sql
SELECT users.*,
       (DATE_FORMAT(users.created_at, '%d-%b-%Y')) as created,
       (IF(users.is_blocked = 1, 'Yes', 'No')) as is_blocked_str,
       (IF(users.is_blocked = 1, 'danger', 'success')) as is_blocked_class,
       IFNULL(CONCAT('http://localhost/upload/avatar/', users.avatar), 'http://localhost/img/default.png') as avatar_link
FROM users
WHERE ... LIKE '%search%'
```

### PostgreSQL
```sql
SELECT users.*,
       (TO_CHAR(users.created_at, 'DD-Mon-YYYY')) as created,
       (CASE WHEN users.is_blocked = 1 THEN 'Yes' ELSE 'No' END) as is_blocked_str,
       (CASE WHEN users.is_blocked = 1 THEN 'danger' ELSE 'success' END) as is_blocked_class,
       COALESCE(CONCAT('http://localhost/upload/avatar/', users.avatar), 'http://localhost/img/default.png') as avatar_link
FROM users
WHERE ...::text ILIKE '%search%'
```

## How It Works

1. **No ServiceProvider** — Pure PSR-4 autoload. Classes are only loaded by PHP when first used.
2. **Auto driver detection** — Grammar is resolved from `DB::connection()->getDriverName()` at build time.
3. **Global search** — Covers all computed (searchable) columns + model `$fillable` columns.
4. **Parameterized queries** — All search and filter values use parameter binding (SQL injection safe).
5. **`searchable` flag** — Columns added with `searchable: false` are included in SELECT but excluded from global search. Useful for IDs, CSS classes, or display-only expressions.

## License

MIT
