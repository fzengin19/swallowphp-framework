<?php

namespace Framework;

use Framework\Database;
use DateTime;

class Model
{

    protected static $id;

    /**
     * The instance of the Database class.
     *
     * @var Database
     */
    protected static Database $database;

    /**
     * The name of the table associated with the model.
     *
     * @var string
     */
    protected static string $table = '';

    /**
     * The array of model attributes that correspond to columns in the database table.
     * These are dynamically filled during object retrieval or instantiation.
     *
     * @var array
     */
    protected array $attributes = [];

    /**
     * Magic method to get the value of a model attribute or related method.
     * 
     * This method is triggered when invoking inaccessible or non-existing properties.
     * It first checks whether the attribute is available within the $attributes array.
     * If not, it checks if there is a method with the same name as the attribute,
     * and if such method exists, it is called. Otherwise, it returns the property directly.
     *
     * @param string $attribute The name of the attribute to get.
     * @return mixed The value of the attribute or the return value of the method, or the property.
     */
    public function __get($attribute)
    {
        if (isset($this->attributes[$attribute])) {
            // Return the attribute from the attributes array if it exists
            return $this->attributes[$attribute];
        } elseif (method_exists($this, $attribute)) {
            return call_user_func([$this, $attribute]);
        } elseif (property_exists($this, $attribute)) {
            return $this->$attribute;
        }
        // Fallback: return the property directly

    }

    /**
     * Magic method to set the value of a model attribute.
     *
     * This method is triggered when writing data to inaccessible or non-existing properties.
     * It checks if a property with the given key already exists on the model.
     * If it does, the property is updated; otherwise, the key-value pair is added to the attributes array.
     *
     * @param string $key The attribute key to set.
     * @param mixed $value The value to set for the attribute.
     * @return void
     */
    public function __set($key, $value)
    {
        if (isset($this->$key)) {
            // Update the property if it exists
            $this->$key = $value;
        } else {
            // Otherwise, add the key-value pair to the attributes array
            $this->attributes[$key] = $value;
        }
    }
    /**
     * Constructor for the class.
     *
     * @param string $table The name of the table.
     * @param array $data An array of data to fill the object with.
     * @return void
     */
    public function __construct($table = null, $data = [])
    {
        if (null !== $table) {
            static::$table = $table;
        }

        if (count($data) > 0) {
            $this->fill($data);
        }
        static::initializeDatabase();
    }

    /**
     * Initialize the database instance.
     *
     * @return void
     */
    protected static function initializeDatabase()
    {

        if (!isset(static::$database)) {
            static::$database = new Database();
        }

        static::$database->table(static::$table);
    }

    /**
     * Set the table for the query.
     *
     * @param string $table The name of the table.
     * @return Model
     */
    public static function table(string $table)
    {
        static::$table = $table;
        // Database bağlantısını burada başlatın.
        static::initializeDatabase();
        return new static();
    }
    /**
     * Create a new model instance and insert it into the database.
     *
     * @param array $data The data to insert as an associative array.
     * @return Model The created model instance.
     */
    public static function create(array $data)
    {
        static::initializeDatabase();
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $id = static::$database->insert($data);

        // Create an instance of the appropriate model
        $model = static::createModelInstance();

        // Fill the model with data and set the ID
        $model->fill($data);
        $model->id = $id;

        return $model;
    }
    /**
     * Set the columns to select.
     *
     * @param array $columns The columns to select.
     * @return Model
     */
    public static function select(array $columns = ['*'])
    {
        static::initializeDatabase();
        static::$database->select($columns);
        return new static();
    }
    /**
     * Add a where clause to the query.
     *
     * @param string $column The column name.
     * @param string $operator The comparison operator.
     * @param mixed $value The value to compare.
     * @return Model
     */
    public static function where(string $column, string $operator, $value)
    {

        static::initializeDatabase();
        static::$database->where($column, $operator, $value);
        return new static();
    }

    /**
     * Add an order by clause to the query.
     *
     * @param string $column The column to order by.
     * @param string $direction The sort direction (ASC or DESC).
     * @return Model
     */
    public static function orderBy(string $column, string $direction = 'ASC')
    {
        static::initializeDatabase();
        static::$database->orderBy($column, $direction);
        return new static();
    }

    /**
     * Set the limit for the query.
     *
     * @param int $limit The maximum number of rows to return.
     * @return Model
     */
    public static function limit(int $limit)
    {
        static::initializeDatabase();
        static::$database->limit($limit);
        return new static();
    }

    /**
     * Set the offset for the query.
     *
     * @param int $offset The number of rows to skip.
     * @return Model
     */
    public static function offset(int $offset)
    {
        static::initializeDatabase();
        static::$database->offset($offset);
        return new static();
    }


    /**
     * Retrieves data from the database and returns either an array of model instances or a single model instance.
     *
     * @return |array Model[] Returns an array of model instances if the result is an array, otherwise returns a single model instance.
     */
    public static function get()
    {
        static::initializeDatabase();
        $result = static::$database->get();
        if (is_array($result)) {
            $models = [];
            foreach ($result as  $value) {
                $model = static::createModelInstance();
                $model->fill($value);
                array_push($models, $model);
            }
            return $models;
        }

        $model = static::createModelInstance();

        $model->fill($result);

        return $model;
    }



    /**
     * Retrieves the first record from the database and returns a model instance.
     *
     * This function initializes the database, executes the 'first' query and
     * returns a model instance filled with the retrieved data. If no data is
     * found, it returns null.
     *
     * @return Model|null Returns a model instance filled with the retrieved data, or null if no data is found.
     */
    public static function first()
    {
        // Initialize the database
        static::initializeDatabase();

        // Execute the 'first' query and get the result
        $result = static::$database->first();

        // If data is found, create a model instance and fill it with the data
        if ($result) {
            // Create an instance of the appropriate model
            $model = static::createModelInstance();

            // Fill the model with data
            $model->fill($result);

            // Return the model instance
            return $model;
        }

        // Return null if no data is found
        return null;
    }


    /**
     * Sets the 'created_at' attribute to the current date and time if not already set.
     *
     * This function is used to automatically set the 'created_at' attribute
     * before saving a new record to the database. If the 'created_at' attribute
     * is already set, this function does nothing.
     *
     * @return void
     */
    public function addCreatedAt()
    {
        if (!isset($this->attributes['created_at'])) {
            $this->attributes['created_at'] = date('Y-m-d H:i:s');
        }
    }

    public function toArray()
    {
        return $this->attributes;
    }
    /**
     * Save the current object by updating its properties in the database.
     *
     * @return int The number of affected rows.
     */
    public function save()
    {
        $this->addCreatedAt();
        static::where('id', '=', $this->attributes['id']);
        return $this->update(static::toArray());
    }



    /**
     * Creates a new instance of the model class.
     *
     * @return object The new instance of the model class.
     */
    private static function createModelInstance()
    {
        $className = get_called_class();
        return new $className();
    }

    /**
     * Fill the model with data.
     *
     * @param array $data The data to fill the model with.
     * @return void
     */
    public function fill(array $data)
    {
        foreach ($data as $key => $value) {

            $this->attributes[$key] = $value;
        }
    }

    /**
     * Execute an insert query and return the last inserted ID.
     *
     * @param array $data The data to insert as an associative array.
     * @return int The last inserted ID.
     */
    public static function insert(array $data)
    {
        static::initializeDatabase();
        return static::$database->insert($data);
    }

    /**
     * Execute an update query and return the number of affected rows.
     *
     * @param array $data The data to update as an associative array.
     * @return int The number of affected rows.
     */
    public static function update(array $data)
    {
        static::initializeDatabase();
        return static::$database->update($data);
    }

    public function refresh()
    {
        if (isset($this->attributes['id'])) {
            static::initializeDatabase();
            $this->attributes = static::$database->where('id', '=', $this->attributes['id'])->first();
        }
        return new static();
    }

    /**
     * Execute a delete query and return the number of affected rows.
     *
     * @return int The number of affected rows.
     */
    public static function delete()
    {
        static::initializeDatabase();
        return static::$database->delete();
    }

    /**
     * Paginate the query results.
     *
     * @param int $perPage The number of results per page.
     * @param int $page The page number.
     * @return array The paginated result set.
     */
    public static function paginate(int $perPage)
    {
        static::initializeDatabase();
        $data = static::$database->paginate($perPage, $_GET['page'] ?? 1);
        if (is_array($data['data'])) {
            $models = [];
            foreach ($data['data'] as  $value) {
                $model = static::createModelInstance();
                $model->fill($value);
                array_push($models, $model);
            }
            $data['data'] = $models;
            return $data;
        }
        if (is_null($data['data'])) {
            $model = static::createModelInstance();
            $model->fill($data);
            return $data;
        }
    }




    /**
     * Perform a raw WHERE clause on the query.
     *
     * @param string $query The raw WHERE clause
     * @return self
     */
    public static function whereRaw($query)
    {
        static::initializeDatabase();
        static::$database->whereRaw($query);
        return new static();
    }


    /**
     * Paginates the data using a cursor pagination strategy.
     *
     * @param int $perPage The number of items per page.
     * @param int $page The current page number. Default is 1.
     * @return array The paginated data.
     */
    public static function cursorPaginate(int $perPage, int $page = 1)
    {
        static::initializeDatabase();
        $data = static::$database->cursorPaginate($perPage, $page);
        if (is_array($data['data'])) {
            $models = [];
            foreach ($data['data'] as  $value) {
                $model = static::createModelInstance();
                $model->fill($value);
                array_push($models, $model);
            }
            $data['data'] = $models;
            return $data;
        }
        if (is_null($data['data'])) {
            $model = static::createModelInstance();
            $model->fill($data);
            return $data;
        }
    }
}
