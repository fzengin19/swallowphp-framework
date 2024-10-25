<?php

namespace SwallowPHP\Framework;

use SwallowPHP\Framework\Database;
use DateTime;
use InvalidArgumentException;

/**
 * Model class manages database operations and data manipulation.
 */
class Model
{
    /** @var int|null Model ID */
    protected static ?int $id = null;

    /** @var Database Database connection */
    protected static Database $database;

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
        if ($table !== null) {
            static::$table = $table;
        }

        if (!empty($data)) {
            $this->fill($data);
        }

        $this->original = $this->attributes;
        static::initializeDatabase();
    }

    /**
     * Get attribute value
     * 
     * @param string $attribute Attribute name
     * @return mixed Attribute value
     * @throws InvalidArgumentException If attribute not found
     */
    public function __get(string $attribute)
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
     * Initialize database connection
     */
    protected static function initializeDatabase(): void
    {
        if (!isset(static::$database)) {
            static::$database = new Database();
        }
        static::$database->table(static::$table);
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
        static::initializeDatabase();
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

        static::initializeDatabase();
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $id = static::$database->insert($data);

        $model = static::createModelInstance();
        $model->fill($data);
        $model->id = $id;

        static::fireEvent('created', $model);

        return $model;
    }

    /**
     * Specify columns to select
     * 
     * @param array $columns Column names
     * @return self Model instance
     */
    public static function select(array $columns = ['*']): self
    {
        static::initializeDatabase();
        static::$database->select($columns);
        return new static();
    }

    /**
     * Add where condition
     * 
     * @param string $column Column name
     * @param string $operator Comparison operator
     * @param mixed $value Comparison value
     * @return self Model instance
     */
    public static function where(string $column, string $operator, $value): self
    {
        static::initializeDatabase();
        static::$database->where($column, $operator, $value);
        return new static();
    }

    public static function orWhere(string $column, string $operator, $value): self
    {
        static::initializeDatabase();
        static::$database->orWhere($column, $operator, $value);
        return new static();
    }

    public static function whereIn(string $column, array $values): self
    {
        static::initializeDatabase();
        static::$database->whereIn($column, $values);
        return new static();
    }

    public static function whereBetween(string $column, $start, $end): self
    {
        static::initializeDatabase();
        static::$database->whereBetween($column, $start, $end);
        return new static();
    }

    public static function orderBy(string $column, string $direction = 'ASC'): self
    {
        static::initializeDatabase();
        static::$database->orderBy($column, $direction);
        return new static();
    }

    public static function limit(int $limit): self
    {
        static::initializeDatabase();
        static::$database->limit($limit);
        return new static();
    }

    public static function offset(int $offset): self
    {
        static::initializeDatabase();
        static::$database->offset($offset);
        return new static();
    }

    public static function get(): array
    {
        static::initializeDatabase();
        $result = static::$database->get();
        return static::hydrateModels($result);
    }

    public static function first(): ?self
    {
        static::initializeDatabase();
        $result = static::$database->first();
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
            $array[$key]= $this->castAttribute($key, $attribute);
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
        $this->addCreatedAt();
        $this->attributes['updated_at'] = date('Y-m-d H:i:s');

        static::fireEvent('saving', $this);

        if (isset($this->attributes['id'])) {
            static::fireEvent('updating', $this);
            $result = $this->update($this->getDirty());
            static::fireEvent('updated', $this);
        } else {
            static::fireEvent('creating', $this);
            $result = static::insert($this->toArray());
            $this->id = $result;
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
                $this->attributes[$key] = $value;
            }
        }
    }

    public static function insert(array $data): int
    {
        static::initializeDatabase();
        return static::$database->insert($data);
    }

    public static function update(array $data): int
    {
        static::initializeDatabase();
        return static::$database->update($data);
    }

    public function refresh(): self
    {
        if (isset($this->attributes['id'])) {
            static::initializeDatabase();
            $this->attributes = static::$database->where('id', '=', $this->attributes['id'])->first();
            $this->syncOriginal();
        }
        return $this;
    }

    public static function delete(): int
    {
        static::initializeDatabase();
        static::fireEvent('deleting', new static());
        $result = static::$database->delete();
        static::fireEvent('deleted', new static());
        return $result;
    }

    public static function paginate(int $perPage): array
    {
        static::initializeDatabase();
        $data = static::$database->paginate($perPage, $_GET['page'] ?? 1);
        $data['data'] = static::hydrateModels($data['data']);
        return $data;
    }

    public static function whereRaw(string $query): self
    {
        static::initializeDatabase();
        static::$database->whereRaw($query);
        return new static();
    }

    public static function cursorPaginate(int $perPage, int $page = 1): array
    {
        static::initializeDatabase();
        $data = static::$database->cursorPaginate($perPage, $page);
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
        return array_diff_assoc($this->attributes, $this->original);
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
        return $relatedInstance::where($foreignKey, '=', $this->$localKey)->get();
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
        return $relatedInstance::where($ownerKey, '=', $this->$foreignKey)->first();
    }
}
