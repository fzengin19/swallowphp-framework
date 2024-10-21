<?php

namespace SwallowPHP\Framework;

use SwallowPHP\Framework\Database;
use DateTime;
use InvalidArgumentException;

class Model
{
    protected static ?int $id = null;
    protected static Database $database;
    protected static string $table = '';
    protected array $attributes = [];
    protected array $original = [];
    protected array $casts = [];
    protected array $dates = ['created_at', 'updated_at'];
    protected array $hidden = [];
    protected array $fillable = [];
    protected array $guarded = ['id'];

    protected static array $eventCallbacks = [];

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

    public function __get(string $attribute)
    {
        if (isset($this->attributes[$attribute])) {
            return $this->castAttribute($attribute, $this->attributes[$attribute]);
        }

        if (method_exists($this, $attribute)) {
            return $this->$attribute();
        }

        if (property_exists($this, $attribute)) {
            return $this->$attribute;
        }

        throw new InvalidArgumentException("Özellik '{$attribute}' bulunamadı.");
    }

    public function __set(string $key, $value): void
    {
        if (in_array($key, $this->fillable) || empty($this->fillable)) {
            $this->attributes[$key] = $value;
        } elseif (in_array($key, $this->guarded)) {
            throw new InvalidArgumentException("Özellik '{$key}' korumalıdır ve doğrudan ayarlanamaz.");
        }
    }

    protected static function initializeDatabase(): void
    {
        if (!isset(static::$database)) {
            static::$database = new Database();
        }
        static::$database->table(static::$table);
    }

    public static function table(string $table): self
    {
        static::$table = $table;
        static::initializeDatabase();
        return new static();
    }

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

    public static function select(array $columns = ['*']): self
    {
        static::initializeDatabase();
        static::$database->select($columns);
        return new static();
    }

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

    public function toArray(): array
    {
        $array = $this->attributes;
        foreach ($this->hidden as $hidden) {
            unset($array[$hidden]);
        }
        return $array;
    }

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
            $result = static::insert($this->attributes);
            $this->id = $result;
            static::fireEvent('created', $this);
        }

        $this->syncOriginal();
        static::fireEvent('saved', $this);

        return $result;
    }

    protected static function createModelInstance(): self
    {
        $className = get_called_class();
        return new $className();
    }

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

    protected static function hydrateModels(array $data): array
    {
        $models = [];
        foreach ($data as $item) {
            $models[] = static::hydrateModel($item);
        }
        return $models;
    }

    protected static function hydrateModel(array $data): self
    {
        $model = static::createModelInstance();
        $model->fill($data);
        $model->syncOriginal();
        return $model;
    }

    protected function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    protected function getDirty(): array
    {
        return array_diff_assoc($this->attributes, $this->original);
    }

    public static function on(string $event, callable $callback): void
    {
        if (!isset(static::$eventCallbacks[$event])) {
            static::$eventCallbacks[$event] = [];
        }
        static::$eventCallbacks[$event][] = $callback;
    }

    protected static function fireEvent(string $event, $payload): void
    {
        if (isset(static::$eventCallbacks[$event])) {
            foreach (static::$eventCallbacks[$event] as $callback) {
                call_user_func($callback, $payload);
            }
        }
    }

    public function hasMany(string $relatedModel, string $foreignKey, string $localKey = 'id'): array
    {
        $relatedInstance = new $relatedModel();
        return $relatedInstance::where($foreignKey, '=', $this->$localKey)->get();
    }

    public function belongsTo(string $relatedModel, string $foreignKey, string $ownerKey = 'id'): ?Model
    {
        $relatedInstance = new $relatedModel();
        return $relatedInstance::where($ownerKey, '=', $this->$foreignKey)->first();
    }
}
