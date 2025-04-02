<?php

namespace SwallowPHP\Framework\Database;

use DateTime;
use InvalidArgumentException;

/**
 * Model class manages database operations and data manipulation.
 */
class Model
{
    /** @var int|null The unique identifier for the model. */
    protected ?int $id = null;

    /** @var string Database table name */
    protected static string $table = '';

    /** @var array Model attributes */
    protected array $attributes = [];

    /** @var array Original model attributes */
    protected array $original = [];

    /** @var array Attribute casts */
    protected array $casts = [];

    /** @var array Date fields */
    protected array $dates = ['created_at', 'updated_at'];

    /** @var array Hidden attributes */
    protected array $hidden = [];

    /** @var array Fillable attributes */
    protected array $fillable = [];

    /** @var array Guarded attributes */
    protected array $guarded = ['id'];

    /** @var array Event callbacks */
    protected static array $eventCallbacks = [];

    /**
     * Model constructor
     *
     * @param string|null $table Table name
     * @param array $data Initial data
     */
    public function __construct(string $table = null, array $data = [])
    {
        if (!empty($data)) {
            $this->fill($data);
        }
        $this->original = $this->attributes;
    }

    /**
     * Get attribute value
     *
     * @param string $attribute Attribute name
     * @return mixed Attribute value
     */
    public function __get(string $attribute): mixed
    {
        if (array_key_exists($attribute, $this->attributes)) {
            return $this->castAttribute($attribute, $this->attributes[$attribute]);
        }
        if (method_exists($this, $attribute)) {
            return $this->$attribute();
        }
        if (property_exists($this, $attribute)) {
            return $this->$attribute;
        }
        return null;
    }

    /**
     * Set attribute value
     *
     * @param string $key Attribute name
     * @param mixed $value Attribute value
     * @throws InvalidArgumentException If trying to set a guarded attribute
     */
    public function __set(string $key, $value): void
    {
        // Use correct logical AND operator '&&'
        if ((in_array($key, $this->fillable) || empty($this->fillable)) && !in_array($key, $this->guarded)) {
             $this->attributes[$key] = $value;
        } elseif (in_array($key, $this->guarded)) {
            throw new InvalidArgumentException("Attribute '{$key}' is protected and cannot be set directly.");
        }
    }

    /**
     * Set table name (Static context - returns a new query builder)
     *
     * @param string $table Table name
     * @return \SwallowPHP\Framework\Database Query builder instance
     */
    public static function table(string $table): Database
    {
        return static::query()->table($table);
    }

    /**
     * Create a new record
     *
     * @param array $data Record data
     * @return self|false Created model instance or false on failure
     */
    public static function create(array $data): self|false
    {
        static::fireEvent('creating', $data);
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');

        $model = static::createModelInstance();
        $model->fill($data);

        if ($model->save() !== false) {
            return $model;
        }
        return false;
    }


    /**
     * Specify columns to select
     *
     * @param array $columns Column names
     * @return \SwallowPHP\Framework\Database
     */
    public static function select(array $columns = ['*']): Database
    {
        return static::query()->select($columns);
    }

    /**
     * Add where condition
     *
     * @param string $column Column name
     * @param string $operator Comparison operator
     * @param mixed $value Comparison value
     * @return \SwallowPHP\Framework\Database
     */
    public static function where(string $column, string $operator, $value): Database
    {
        return static::query()->where($column, $operator, $value);
    }

    public static function orWhere(string $column, string $operator, $value): Database
    {
        return static::query()->orWhere($column, $operator, $value);
    }

    public static function whereIn(string $column, array $values): Database
    {
        return static::query()->whereIn($column, $values);
    }

    public static function whereBetween(string $column, $start, $end): Database
    {
        return static::query()->whereBetween($column, $start, $end);
    }

    public static function orderBy(string $column, string $direction = 'ASC'): Database
    {
        return static::query()->orderBy($column, $direction);
    }

    public static function limit(int $limit): Database
    {
        return static::query()->limit($limit);
    }

    public static function offset(int $offset): Database
    {
        return static::query()->offset($offset);
    }

    /**
     * Get all records matching the query.
     * Returns an array of model instances.
     * @return array<int, static>
     */
    public static function get(): array
    {
        return static::query()->get();
    }

    /**
     * Get the first record matching the query.
     * Returns a single model instance or null.
     * @return static|null
     */
    public static function first(): ?self
    {
         return static::query()->first();
    }

    // addCreatedAt is now handled within save() logic for inserts
    // public function addCreatedAt(): void { ... }

    /**
     * Convert model data to array
     *
     * @return array Model data
     */
    public function toArray(): array
    {
        $array = [];
        foreach ($this->attributes as $key => $value) {
            $array[$key] = $this->__get($key);
        }
        foreach ($this->hidden as $hidden) {
            unset($array[$hidden]);
        }
        return $array;
    }

    /**
     * Save model data (insert or update)
     *
     * @return int|bool Number of affected rows on update, insert ID on create, or false on failure
     */
    public function save(): int|bool
    {
        $this->attributes['updated_at'] = date('Y-m-d H:i:s');
        static::fireEvent('saving', $this);
        $result = false;

        // Use correct logical AND operator '&&'
        if (!empty($this->attributes['id'])) { // Existing model (UPDATE)
            static::fireEvent('updating', $this);
            $dirtyData = $this->getDirty();
            if (!empty($dirtyData)) {
                $dirtyData['updated_at'] = $this->attributes['updated_at'];
                $result = static::query()
                                ->where('id', '=', $this->attributes['id'])
                                ->update($dirtyData);
            } else {
                $result = 0;
            }
            if ($result !== false) {
                static::fireEvent('updated', $this);
            }
        } else { // New model (INSERT)
            $this->attributes['created_at'] = $this->attributes['created_at'] ?? date('Y-m-d H:i:s');
            static::fireEvent('creating', $this);
            $insertId = static::query()->insert($this->attributes);
            // Use correct logical AND operator '&&'
            if ($insertId !== false && $insertId > 0) {
                $this->id = (int) $insertId;
                $this->attributes['id'] = $this->id;
                $result = $this->id;
                static::fireEvent('created', $this);
            } else {
                $result = false;
            }
        }

        if ($result !== false) {
            $this->syncOriginal();
            static::fireEvent('saved', $this);
        }
        return $result;
    }


    /**
     * Create a new model instance
     *
     * @return self New model instance
     */
    protected static function createModelInstance(): self
    {
        $className = get_called_class();
        return new $className();
    }

    /**
     * Fill model data
     *
     * @param array $data Data to fill
     */
    public function fill(array $data): void
    {
        foreach ($data as $key => $value) {
             // Use correct logical AND operator '&&'
            if ((in_array($key, $this->fillable) || empty($this->fillable)) && !in_array($key, $this->guarded)) {
                 $this->attributes[$key] = $value;
            }
        }
    }


    /**
     * Static insert - bypasses events and fillable/guarded checks. Use with caution.
     * @return int|false Insert ID or false
     */
    public static function insert(array $data): int|false
    {
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');
        return static::query()->insert($data);
    }

    /** Static update is disallowed. */
    public static function update(array $data): int
    {
        throw new \LogicException("Static update without conditions is not supported. Use query()->where(...)->update(...) instead.");
    }

    /** Refresh model attributes from database. */
    public function refresh(): self
    {
        if (empty($this->attributes['id'])) {
            throw new InvalidArgumentException('Model must have an ID to refresh');
        }
        $freshModel = static::query()->where('id', '=', $this->attributes['id'])->first();
        if (!$freshModel instanceof static) {
            throw new InvalidArgumentException('Model not found in database with ID: ' . $this->attributes['id']);
        }
        $this->attributes = $freshModel->attributes;
        $this->syncOriginal();
        return $this;
    }

    /** Static delete is disallowed. */
    public static function delete(): int
    {
        throw new \LogicException("Static delete without conditions is not supported. Use query()->where(...)->delete() instead.");
    }

    /** Paginate results. Returns hydrated models. */
    public static function paginate(int $perPage, int $page = 1): array
    {
        return static::query()->paginate($perPage, $page);
    }

    public static function whereRaw(string $query, array $bindings = []): Database
    {
        return static::query()->whereRaw($query, $bindings);
    }

    /** Paginate using cursor. Returns hydrated models. */
    public static function cursorPaginate(int $perPage, ?string $cursor = null): array
    {
        return static::query()->cursorPaginate($perPage, $cursor);
    }

    /**
     * Cast attribute value
     *
     * @param string $key Attribute name
     * @param mixed $value Attribute value
     * @return mixed Casted value
     */
    protected function castAttribute(string $key, $value)
    {
        $castType = $this->casts[$key] ?? null;
         // Use correct logical AND operator '&&'
        if (is_null($value) && !in_array($castType, ['date', 'datetime', 'array', 'object'])) {
             return null;
        }
        if (!$castType) {
            if (in_array($key, $this->dates)) {
                 $castType = 'datetime';
            } else {
                 return $value;
            }
        }

        switch ($castType) {
            case 'int': case 'integer':
                return is_numeric($value) ? (int) $value : null;
            case 'real': case 'float': case 'double':
                return is_numeric($value) ? (float) $value : null;
            case 'string':
                return (string) $value;
            case 'bool': case 'boolean':
                if (is_string($value)) {
                     return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                }
                return (bool) $value;
            case 'array':
                return is_array($value) ? $value : json_decode($value, true);
            case 'object':
                 return is_object($value) ? $value : json_decode($value);
            case 'date': case 'datetime':
                 if ($value instanceof DateTime) return $value;
                 if (empty($value)) return null;
                 try { return new DateTime($value); } catch (\Exception $e) { return null; }
            default:
                return $value;
        }
    }

    /**
     * Hydrate multiple models (Static helper for Database class)
     *
     * @param array $data Raw data rows
     * @return array Hydrated models
     */
    public static function hydrateModels(array $data): array
    {
        $models = [];
        foreach ($data as $item) {
            if (is_array($item)) {
                 $models[] = static::hydrateModel($item);
            }
        }
        return $models;
    }

    /**
     * Hydrate a single model (Static helper for Database class)
     *
     * @param array $data Raw data row
     * @return self Hydrated model
     */
    protected static function hydrateModel(array $data): self
    {
        $model = static::createModelInstance();
        $model->attributes = $data;
        $idKey = 'id'; // Assume primary key is 'id'
         // Use correct logical AND operator '&&'
        if (array_key_exists($idKey, $data) && property_exists($model, $idKey)) {
            $castType = $model->casts[$idKey] ?? null;
            $model->id = ($castType === 'integer' || $castType === 'int') ? (int)$data[$idKey] : $data[$idKey];
        }
        $model->syncOriginal();
        return $model;
    }

    /** Sync original data */
    protected function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    /** Get changed attributes */
    protected function getDirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                 $dirty[$key] = $value;
            }
        }
        return $dirty;
    }


    /** Add event listener */
    public static function on(string $event, callable $callback): void
    {
        $class = get_called_class();
        static::$eventCallbacks[$class][$event][] = $callback;
    }

    /** Fire an event */
    protected static function fireEvent(string $event, $payload): void
    {
        $class = get_called_class();
        if (isset(static::$eventCallbacks[$class][$event])) {
             foreach (static::$eventCallbacks[$class][$event] as $callback) {
                 if (call_user_func($callback, $payload) === false) {
                    // break;
                 }
             }
        }
    }


    /** Define a one-to-many relationship */
    public function hasMany(string $relatedModel, string $foreignKey, string $localKey = 'id'): array
    {
        if (!class_exists($relatedModel)) {
            throw new \RuntimeException("Related model not found: {$relatedModel}");
        }
        $localValue = $this->attributes[$localKey] ?? null;
        if ($localValue === null) {
             return [];
        }
        return $relatedModel::query()->where($foreignKey, '=', $localValue)->get();
    }

    /** Define a belongs-to relationship */
    public function belongsTo(string $relatedModel, string $foreignKey, string $ownerKey = 'id'): ?Model
    {
         if (!class_exists($relatedModel)) {
             throw new \RuntimeException("Related model not found: {$relatedModel}");
         }
         $foreignValue = $this->attributes[$foreignKey] ?? null;
         if ($foreignValue === null) {
              return null;
         }
        return $relatedModel::query()->where($ownerKey, '=', $foreignValue)->first();
    }


    /** Get the table associated with the model. */
    protected static function getTable(): string
    {
        if (empty(static::$table)) {
             $className = basename(str_replace('\\', '/', get_called_class()));
             $tableName = strtolower(preg_replace('/(?<!\A)[A-Z]/', '_$0', $className));
              // Use correct logical AND operator '&&'
             if (str_ends_with($tableName, 'y') && !in_array(substr($tableName, -2, 1), ['a','e','i','o','u'])) {
                  $tableName = substr($tableName, 0, -1) . 'ies';
             } else if (!str_ends_with($tableName, 's')) {
                 $tableName .= 's';
             }
             static::$table = $tableName;
        }
        return static::$table;
    }


    /**
     * Begin querying the model.
     * Returns a query builder instance associated with this model class.
     * @return \SwallowPHP\Framework\Database
     */
    public static function query(): Database
    {
        if (!class_exists(Database::class)) {
             throw new \RuntimeException("Database class not found.");
        }
        $builder = \SwallowPHP\Framework\Foundation\App::container()->get(Database::class);
        $builder->reset();
        $builder->table(static::getTable());
        $builder->setModel(static::class); // Associate builder with this model class
        return $builder;
    }
}