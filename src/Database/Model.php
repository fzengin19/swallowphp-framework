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

    /** @var Database Database connection */
    // protected static Database $database; // Removed: Will use instance-based query builder

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
        // Removed static table setting from constructor.
        // Table name should be defined via static $table property in subclasses
        // or derived by getTable() method.

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
        if (in_array($key, $this->fillable) || empty($this->fillable)) {
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
     * @return self Created model instance
     */
    public static function create(array $data): self
    {
        static::fireEvent('creating', $data);

        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');

        $model = static::createModelInstance();
        $model->fill($data);
        $model->save();

        static::fireEvent('created', $model);

        return $model;
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
        foreach ($this->hidden as $hidden) {
            unset($array[$hidden]);
        }

        foreach ($this->attributes as $key => $attribute) {
            $array[$key] = $this->castAttribute($key, $attribute);
        }
        return $array;
    }

    /**
     * Save model data
     * 
     * @return int Number of affected rows
     */
    public function save(): int
    {
        $this->attributes['updated_at'] = date('Y-m-d H:i:s');

        static::fireEvent('saving', $this);

        if (isset($this->attributes['id'])) {
            static::fireEvent('updating', $this);
            // Use the query builder to update the specific record
            $result = static::query()
                            ->where('id', '=', $this->attributes['id'])
                            ->update($this->getDirty());
            static::fireEvent('updated', $this);
        } else {
            $this->addCreatedAt();
            static::fireEvent('creating', $this);
            // Use the query builder to insert the new record
            $result = static::query()->insert($this->toArray());
            $this->id = (int) $result; // Assign the insert ID back to the model (cast to int)
            static::fireEvent('created', $this);
        }

        $this->syncOriginal();
        static::fireEvent('saved', $this);

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
            if (in_array($key, $this->fillable) || empty($this->fillable)) {
                $this->attributes[$key] = $this->castAttribute($key, $value);
            }
        }
    }

    public static function insert(array $data): int
    {
        return static::query()->insert($data); // Start query and execute insert
    }

    public static function update(array $data): int
    {
        // This static update is problematic as it lacks WHERE conditions.
        // It should likely be removed or require conditions.
        // For now, let it start a query, but it will update ALL rows without a where clause!
        // Consider deprecating or changing its behavior.
        // Triggering an error might be safer:
        throw new \LogicException("Static update without conditions is not supported. Use query()->where(...)->update(...) instead.");
        // return static::query()->update($data);
    }

    public function refresh(): self
    {
        if (!isset($this->attributes['id'])) {
            throw new InvalidArgumentException('Model must have an ID to refresh');
        }

        $result = static::query()->where('id', '=', $this->attributes['id'])->first(); // Use query builder

        if (!$result) {
            throw new InvalidArgumentException('Model not found in database');
        }

        $this->attributes = $result;
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
        static::fireEvent('deleting', new static());
        // The actual delete operation needs a preceding where clause via query()
        // This static delete without conditions is dangerous.
        // Triggering an error might be safer:
        throw new \LogicException("Static delete without conditions is not supported. Use query()->where(...)->delete() instead.");
        // $result = static::query()->delete(); // This would delete ALL rows!
        static::fireEvent('deleted', new static());
        return $result;
    }

    // Removed duplicate: public static function paginate(int $perPage): array
    public static function paginate(int $perPage, int $page = 1): array
    {
        // Pass the $page parameter down to the Database query builder
        $data = static::query()->paginate($perPage, $page);
        $data['data'] = static::hydrateModels($data['data']);
        return $data;
    }

    public static function whereRaw(string $query, array $bindings = []): Database
    {
        return static::query()->whereRaw($query, $bindings);
    }

    // Removed duplicate: public static function cursorPaginate(int $perPage, int $page = 1): array
    public static function cursorPaginate(int $perPage, ?string $cursor = null): array
    {
        // Pass the $cursor parameter down to the Database query builder
        $data = static::query()->cursorPaginate($perPage, $cursor);
        $data['data'] = static::hydrateModels($data['data']);
        return $data;
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
        if (!isset($this->casts[$key])) {
            return $value;
        }
        switch ($this->casts[$key]) {
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
                return json_decode($value, true);
            case 'object':
                return json_decode($value);
            case 'date':
            case 'datetime':
                return new DateTime($value);
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
            $models[] = static::hydrateModel($item);
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
        $model->fill($data);
        $model->syncOriginal();
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
                $dirty[$key] = $this->castAttribute($key, $value);
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
        if (!isset(static::$eventCallbacks[$event])) {
            static::$eventCallbacks[$event] = [];
        }
        static::$eventCallbacks[$event][] = $callback;
    }

    /**
     * Fire an event
     * 
     * @param string $event Event name
     * @param mixed $payload Event data
     */
    protected static function fireEvent(string $event, $payload): void
    {
        if (isset(static::$eventCallbacks[$event])) {
            foreach (static::$eventCallbacks[$event] as $callback) {
                call_user_func($callback, $payload);
            }
        }
    }

    /**
     * Define a one-to-many relationship
     * 
     * @param string $relatedModel Related model class
     * @param string $foreignKey Foreign key
     * @param string $localKey Local key
     * @return array Related models
     */
    public function hasMany(string $relatedModel, string $foreignKey, string $localKey = 'id'): array
    {
        $relatedInstance = new $relatedModel();
        // Use the query builder on the related model
        return $relatedInstance::query()->where($foreignKey, '=', $this->$localKey)->get();
    }

    /**
     * Define a belongs-to relationship
     * 
     * @param string $relatedModel Related model class
     * @param string $foreignKey Foreign key
     * @param string $ownerKey Owner key
     * @return Model|null Related model
     */
    public function belongsTo(string $relatedModel, string $foreignKey, string $ownerKey = 'id'): ?Model
    {
        $relatedInstance = new $relatedModel();
        // Use the query builder on the related model
        return $relatedInstance::query()->where($ownerKey, '=', $this->$foreignKey)->first();
    }


    /**
     * Get the table associated with the model.
     * Allows overriding in subclasses.
     *
     * @return string
     */
    protected static function getTable(): string
    {
        // If $table is not set in subclass, generate from class name
        if (empty(static::$table)) {
             // Basic pluralization and snake_case conversion
             $className = basename(str_replace('\\', '/', get_called_class()));
             $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));
             // Simple pluralization (add 's'), might need a more robust library for complex cases
             static::$table = $tableName . 's';
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
        $builder = new Database(); // Creates a new builder instance
        $builder->table(static::getTable()); // Sets the table for this query
        return $builder;
    }

}