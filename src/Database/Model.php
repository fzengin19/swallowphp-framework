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
        if ((in_array($key, $this->fillable) || empty($this->fillable)) && !in_array($key, $this->guarded)) {
             $this->attributes[$key] = $value;
        } elseif (in_array($key, $this->guarded)) {
            throw new InvalidArgumentException("Attribute '{$key}' is protected and cannot be set directly.");
        }
    }

    /**
     * Set table name
     *
     * @param string $table Table name
     * @return self Model instance
     */
    public static function table(string $table): self
    {
        static::$table = $table;
        return new static();
    }

    /**
     * Create a new record
     *
     * @param array $data Record data
     * @return self Created model instance or false on failure
     */
    public static function create(array $data): self|false
    {
        static::fireEvent('creating', $data);

        // Ensure created_at is set if not provided
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        // Ensure updated_at is set if not provided (important for insert via attributes)
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');

        $model = static::createModelInstance();
        $model->fill($data); // Uses fillable

        if ($model->save() !== false) { // save() returns insert ID or false
            // Event is fired within save()
            return $model;
        }
        return false; // Indicate failure
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

    public static function get(): array
    {
        $result = static::query()->get(); // Start query and execute get
        return static::hydrateModels($result);
    }

    public static function first(): ?self
    {
        $result = static::query()->first(); // Start query and execute first
        return $result ? static::hydrateModel($result) : null;
    }

    public function addCreatedAt(): void
    {
        if (!isset($this->attributes['created_at'])) {
            $this->attributes['created_at'] = date('Y-m-d H:i:s');
        }
    }

    /**
     * Convert model data to array
     *
     * @return array Model data
     */
    public function toArray(): array
    {
        $array = $this->attributes;

        foreach ($this->attributes as $key => $attribute) {
            $array[$key] = $this->castAttribute($key, $attribute);
        }

        // Remove hidden attributes after casting
        foreach ($this->hidden as $hidden) {
            unset($array[$hidden]);
        }

        return $array;
    }

    /**
     * Save model data
     *
     * @return int|bool Number of affected rows on update, insert ID on create, or false on failure
     */
    public function save(): int|bool
    {
        $this->attributes['updated_at'] = date('Y-m-d H:i:s');

        static::fireEvent('saving', $this);

        $result = false; // Initialize result

        // Check if ID exists and is not empty/null/zero etc.
        if (!empty($this->attributes['id'])) {
            static::fireEvent('updating', $this);
            $dirtyData = $this->getDirty();
            if (!empty($dirtyData)) { // Only update if there are changes
                // Use the query builder to update the specific record
                $result = static::query()
                                ->where('id', '=', $this->attributes['id'])
                                ->update($dirtyData); // update() returns affected rows count (int)
            } else {
                $result = 0; // No changes, affected rows is 0
            }
            static::fireEvent('updated', $this);
        } else {
            $this->addCreatedAt();
            static::fireEvent('creating', $this);
            // Use raw attributes for insert, ensuring timestamps are included
            $insertId = static::query()->insert($this->attributes);
            // insert() returns the last insert ID (int) or false on failure
            if ($insertId !== false && $insertId > 0) {
                $this->id = (int) $insertId; // Assign the insert ID back to the model
                $this->attributes['id'] = $this->id; // Also update attributes array
                $result = $this->id; // Return the new ID on successful insert
                static::fireEvent('created', $this);
            } else {
                $result = false; // Indicate failure
            }
        }

        if ($result !== false) { // Only sync if save was potentially successful
            $this->syncOriginal();
            static::fireEvent('saved', $this);
        }

        return $result; // Return insert ID, affected rows count, or false
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
            // Check fillable OR if fillable is empty (allowing all non-guarded)
            // AND check not guarded
            if ((in_array($key, $this->fillable) || empty($this->fillable)) && !in_array($key, $this->guarded)) {
                 // Do not cast here, cast when getting or saving if necessary
                 $this->attributes[$key] = $value;
            }
        }
    }


    public static function insert(array $data): int|false
    {
        // Note: This static insert bypasses fillable/guarded checks and events. Use with caution.
        return static::query()->insert($data); // insert() returns insert ID or false
    }

    public static function update(array $data): int
    {
        throw new \LogicException("Static update without conditions is not supported. Use query()->where(...)->update(...) instead.");
    }

    public function refresh(): self
    {
        if (!isset($this->attributes['id'])) {
            throw new InvalidArgumentException('Model must have an ID to refresh');
        }
        $result = static::query()->where('id', '=', $this->attributes['id'])->first();
        if (!$result) {
            throw new InvalidArgumentException('Model not found in database with ID: ' . $this->attributes['id']);
        }
        $this->attributes = $result; // Overwrite attributes with fresh data
        $this->syncOriginal();
        return $this;
    }

    /**
     * Delete record(s)
     *
     * @return int Number of affected rows
     */
    public static function delete(): int
    {
        throw new \LogicException("Static delete without conditions is not supported. Use query()->where(...)->delete() instead.");
    }

    public static function paginate(int $perPage, int $page = 1): array
    {
        $paginatorData = static::query()->paginate($perPage, $page);
        $paginatorData['data'] = static::hydrateModels($paginatorData['data']);
        return $paginatorData;
    }

    public static function whereRaw(string $query, array $bindings = []): Database
    {
        return static::query()->whereRaw($query, $bindings);
    }

    public static function cursorPaginate(int $perPage, ?string $cursor = null): array
    {
        $paginatorData = static::query()->cursorPaginate($perPage, $cursor);
        $paginatorData['data'] = static::hydrateModels($paginatorData['data']);
        return $paginatorData;
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

        // Skip casting if value is null and the cast type isn't explicitly handling nulls
        if (is_null($value) && !in_array($castType, ['date', 'datetime', 'array', 'object'])) {
             return null;
        }

        if (!$castType) {
            return $value;
        }

        switch ($castType) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return (float) $value;
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'array':
                return is_array($value) ? $value : json_decode($value, true);
            case 'object':
                 return is_object($value) ? $value : json_decode($value);
            case 'date':
            case 'datetime':
                 if ($value instanceof DateTime) return $value;
                 try { return new DateTime($value); } catch (\Exception $e) { return null; }
            default:
                return $value;
        }
    }

    /**
     * Hydrate multiple models
     *
     * @param array $data Raw data
     * @return array Hydrated models
     */
    protected static function hydrateModels(array $data): array
    {
        $models = [];
        foreach ($data as $item) {
            if (is_array($item)) { // Ensure item is an array
                 $models[] = static::hydrateModel($item);
            }
        }
        return $models;
    }

    /**
     * Hydrate a single model
     *
     * @param array $data Raw data
     * @return self Hydrated model
     */
    protected static function hydrateModel(array $data): self
    {
        $model = static::createModelInstance();
        $model->attributes = $data; // Directly set attributes
        $model->syncOriginal(); // Set original state
        return $model;
    }

    /**
     * Sync original data
     */
    protected function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    /**
     * Get changed attributes
     *
     * @return array Changed attributes
     */
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


    /**
     * Add event listener
     *
     * @param string $event Event name
     * @param callable $callback Callback function
     */
    public static function on(string $event, callable $callback): void
    {
        $class = get_called_class(); // Store events per class
        if (!isset(static::$eventCallbacks[$class][$event])) {
            static::$eventCallbacks[$class][$event] = [];
        }
        static::$eventCallbacks[$class][$event][] = $callback;
    }

    /**
     * Fire an event
     *
     * @param string $event Event name
     * @param mixed $payload Event data
     */
    protected static function fireEvent(string $event, $payload): void
    {
        $class = get_called_class();
        if (isset(static::$eventCallbacks[$class][$event])) {
             foreach (static::$eventCallbacks[$class][$event] as $callback) {
                 if (call_user_func($callback, $payload) === false) {
                    // Optionally allow listeners to stop propagation
                    // break;
                 }
             }
        }
    }


    /**
     * Define a one-to-many relationship
     *
     * @param string $relatedModel Related model class
     * @param string $foreignKey Foreign key on the related model table
     * @param string $localKey Local key on the current model table (usually 'id')
     * @return array Related models
     */
    public function hasMany(string $relatedModel, string $foreignKey, string $localKey = 'id'): array
    {
        if (!class_exists($relatedModel)) {
            throw new \RuntimeException("Related model not found: {$relatedModel}");
        }
        if (!isset($this->attributes[$localKey])) {
             throw new \RuntimeException("Local key '{$localKey}' not found on model " . get_class($this));
        }
        return $relatedModel::query()->where($foreignKey, '=', $this->attributes[$localKey])->get();
    }

    /**
     * Define a belongs-to relationship
     *
     * @param string $relatedModel Related model class
     * @param string $foreignKey Foreign key on the current model table
     * @param string $ownerKey Owner key on the related model table (usually 'id')
     * @return Model|null Related model
     */
    public function belongsTo(string $relatedModel, string $foreignKey, string $ownerKey = 'id'): ?Model
    {
         if (!class_exists($relatedModel)) {
             throw new \RuntimeException("Related model not found: {$relatedModel}");
         }
         if (!isset($this->attributes[$foreignKey])) {
              return null;
         }
        return $relatedModel::query()->where($ownerKey, '=', $this->attributes[$foreignKey])->first();
    }


    /**
     * Get the table associated with the model.
     * Allows overriding in subclasses.
     *
     * @return string
     */
    protected static function getTable(): string
    {
        if (empty(static::$table)) {
             $className = basename(str_replace('\\', '/', get_called_class()));
             $tableName = strtolower(preg_replace('/(?<!\A)[A-Z]/', '_$0', $className));
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
     * Returns a new query builder instance for the model's table.
     *
     * @return \SwallowPHP\Framework\Database An instance of the query builder.
     */
    public static function query(): Database
    {
        if (!class_exists(Database::class)) {
             throw new \RuntimeException("Database class not found.");
        }
        $builder = \SwallowPHP\Framework\Foundation\App::container()->get(Database::class);
        $builder->reset(); // Reset query state
        $builder->table(static::getTable()); // Set table
        return $builder;
    }
}