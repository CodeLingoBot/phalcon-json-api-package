<?php
namespace PhalconRest\API;

/**
 * placeholder for future work
 *
 * @author jjenkins
 *
 */
class BaseModel extends \Phalcon\Mvc\Model
{

    /**
     * singular name of the model
     *
     * @var string|null
     */
    protected $singularName = NULL;

    /**
     * essentially the name of the model
     *
     * @var string|null
     */
    protected $pluralName = NULL;

    /**
     * store a models primary key name in this property
     *
     * @var string
     */
    public $primaryKeyName;

    /**
     * store the actual pkid value as soon as we get a lock on it
     *
     * @var mixed probably an int
     */
    public $primaryKeyValue;

    /**
     * the underlying table name
     * as singular
     *
     * @var string
     */
    public $singularTableName;

    /**
     * the underlyting table name as plural
     *
     * @var string
     */
    public $pluralTableName;

    /**
     * store the full path to this model
     *
     * @var string|null
     */
    private $modelNameSpace = NULL;

    /**
     * list of relationships?
     *
     * @var array
     */
    private $relationships = null;

    /**
     * hold a list of columns that can be published to the api
     * this array is not directly modifed but rather inferred
     * should work, even when sideloading data
     *
     * start as null to detect and only load once
     * all columns - block columns = allow columns
     *
     * @var array
     */
    private $allowColumns = NULL;

    /**
     * hold a list of columns that are to be blocked by the api
     * modify this list to prevent sensitive columns from being displayed
     *
     * a null value means block columns haven't been loaded yet
     * an array represents loaded blockColumns
     *
     * @var mixed
     */
    private $blockColumns = null;

    /**
     * The table this model depends on for it's existance
     * A give away is when the PKID for this model references the parent PKID
     * in the parent model
     *
     * a parent model effectively merges this table into the child table
     * as a consequence, parent table columns are displayed when requesting a child end point
     * child models cannot block these fields from displaying,
     * instead go to the parent model and block them from there
     *
     * @var boolean|string
     */
    public static $parentModel = FALSE;

    /**
     * store one or more parent models that this entity
     * should merge into the final resource
     *
     * stores basic model names, not name spaces
     *
     * @var boolean|array
     */
    protected $parentModels = null;

    /**
     * auto populate a few key values
     */
    public function initialize()
    {
        $this->loadBlockColumns();
    }


    /**
     * for a provided model name, return that model's parent
     *
     * @param string $name
     */
    public static function getParentModel($name)
    {
        $config = self::getDI()->get('config');
        $modelNameSpace = $config['namespaces']['models'];
        $path = $modelNameSpace . $name;
        return $path::$parentModel;
    }

    /**
     * provided to lazy load the model's name
     *
     * @param string type singular|plural
     * @return string
     */
    public function getModelName($type = 'plural')
    {
        if ($type == 'plural') {
            if (isset($this->pluralName)) {
                return $this->pluralName;
            } else {
                $config = $this->getDI()->get('config');
                $modelNameSpace = $config['namespaces']['models'];

                $name = get_class($this);
                $name = str_replace($modelNameSpace, '', $name);
                $this->pluralName = $name;
                return $this->pluralName;
            }
        }

        if ($type == 'singular') {
            if (!isset($this->singularName)) {
                $this->singularName = substr($this->getPluralName(), 0, strlen($this->getPluralName()) - 1);
            }
            return $this->singularName;
        }

        // todo throw and error here?
        return false;
    }

    /**
     * simple function to return the model's full name space
     * relies on getModelName
     * lazy load and cache result
     */
    public function getModelNameSpace()
    {
        if (!isset($this->modelNameSpace)) {
            $config = $this->getDI()->get('config');
            $nameSpace = $config['namespaces']['models'];
            $this->modelNameSpace = $nameSpace . $this->getModelName();
        }

        return $this->modelNameSpace;
    }

    /**
     * will return the primary key name for a given model
     *
     * @return string
     */
    public function getPrimaryKeyName()
    {
        if (!isset($this->primaryKeyName)) {
            // lazy load
            $memory = $this->getDI()->get('memory');
            $attributes = $memory->getPrimaryKeyAttributes($this);
            $attributeKey = $attributes[0];

            // adjust for colMaps if any are provided
            $colMap = $memory->getColumnMap($this);
            if (is_null($colMap)) {
                $this->primaryKeyName = $attributeKey;
            } else {
                $this->primaryKeyName = $colMap[$attributeKey];
            }
        }
        return $this->primaryKeyName;
    }

    /**
     * default behavior is to expect plural table names in schema
     *
     * @param string $type
     * @return string
     */
    public function getTableName($type = 'plural')
    {
        if ($type == 'plural') {
            if (isset($this->pluralTableName)) {
                return $this->pluralTableName;
            } else {
                $this->pluralTableName = $this->getSource();
                return $this->pluralTableName;
            }
        }

        if ($type == 'singular') {
            if (isset($this->singularTableName)) {
                return $this->singularTableName;
            } else {
                $tableName = $this->getTableName('plural');
                // not the smartest way to make a value singular
                $this->singularTableName = substr($tableName, 0, strlen($tableName) - 1);
                return $this->singularTableName;
            }
        }
    }

    /**
     * return the model's current primary key value
     * this is designed to work for a single model "record" and not a collection
     */
    public function getPrimaryKeyValue()
    {
        $key = $this->getPrimaryKeyName();
        return $this->$key;
    }

    /**
     * return all configured relations for a given model
     * use the supplied Relation library
     * @return Relation[]
     */
    public function getRelations()
    {
        if (!isset($this->relationships)) {
            $this->relationships = array();
            // load them manually
            $mm = $this->getModelsManager();
            $relationships = $mm->getRelations(get_class($this));
            // Temporary fix because $mm->getRelations() doesn't support hasManyToMany relations right now.
            // get them separately and merge them
            $mtmRelationships = $mm->getHasManyToMany($this);

            $relationships = array_merge($relationships, $mtmRelationships);

            foreach ($relationships as $relation) {
                // todo load custom relationship
                $this->relationships[] = new Relation($relation, $mm);
            }
        }
        return $this->relationships;
    }

    /**
     * get a particular relationship configured for this model
     *
     * @param $name
     * @return mixed either a relationship object or false
     */
    public function getRelation($name)
    {
        if (!isset($this->relationships)) {
            $relations = $this->getRelations();
        } else {
            $relations = $this->relationships;
        }

        foreach ($relations as $relation) {
            if ($relation->getAlias() == $name) {
                return $relation;
            }
        }
        return false;
    }

    /**
     * a hook to be run when initializing a model
     * write logic here to block columns
     *
     * could be a static list or something more dynamic
     */
    public function loadBlockColumns()
    {
        $blockColumns = [];
        $class = get_class($this);
        $parentModelName = $class::$parentModel;

        if ($parentModelName) {
            $parentModelNameSpace = "\\PhalconRest\\Models\\" . $parentModelName;
            $parentModel = new $parentModelNameSpace();
            $blockColumns = $parentModel->getBlockColumns();

            // the parent model may return null, let's catch and change to an empty array
            // thus indicated that block columns have been "loaded" even if they are blank
            if ($blockColumns == null) {
                $blockColumns = [];
            }
        }
        $this->setBlockColumns($blockColumns, true);
    }

    /**
     * for a given array of column names, add them to the block list
     *
     * @param array $columnList
     *            a list of columns to block for this model
     * @param boolean $clear
     *            should the existing list of blockColums be cleared to an array
     *            this has the affect of initializing the list
     */
    public function setBlockColumns($columnList, $clear = false)
    {
        // reset it requested
        if ($clear) {
            $this->blockColumns = [];
        }

        foreach ($columnList as $column) {
            $this->blockColumns[] = $column;
        }
    }

    /**
     * basic getter for private property
     *
     * @return mixed
     */
    public function getBlockColumns()
    {
        // load columns if they haven't been loaded yet
        if ($this->blockColumns === null) {
            $this->loadBlockColumns();
        }
        // return block columns
        return $this->blockColumns;
    }

    /**
     * get the private notifyColumns property
     */
    public function getNotifyColumns()
    {
        return null;
    }

    /**
     * - return fields to be included when building a resource
     * - to be used from an entity
     * - works when side loading!
     * - will exclude any fields listed in $this->blockFields
     *
     * @param boolean $nameSpace should the resulting array have a nameSpace prefix?
     * @return array
     */
    public function getAllowedColumns($nameSpace = true)
    {
        if ($this->allowColumns == NULL) {
            // load block columns if uninitialized
            if ($this->blockColumns == null) {
                $this->loadBlockColumns();
            }

            // prefix namespace if requested
            if ($nameSpace) {
                $modelNameSpace = $this->getModelNameSpace() . '.';
            } else {
                $modelNameSpace = null;
            }

            $allowColumns = array();

            $colMap = $this->getAllColumns();

            foreach ($colMap as $key => $value) {
                if (array_search($value, $this->blockColumns) === false) {
                    $allowColumns[] = $modelNameSpace . $value;
                }
            }
            $this->allowColumns = $allowColumns;
        }

        return $this->allowColumns;
    }

    /**
     * return what should be a full set of columns for the model
     */
    public function getAllColumns()
    {
        // build a list of columns for this model
        $metaData = $this->getDI()->get('memory');
        $colMap = $metaData->getColumnMap($this);
        if (is_null($colMap)) {
            // but if it isn't present, fall back to attributes
            $colMap = $metaData->getAttributes($this);
        }
        return $colMap;
    }

    /**
     * ask this entity for all parents from the model and up the chain
     * lazy load and cache
     *
     * @param bool $nameSpace
     * should the parent names be formatted as a full namespace?
     *
     * @return array $parents
     */
    public function getParentModels($nameSpace = false)
    {
        // first load parentModels
        if (!isset($parentModels)) {
            $config = $this->getDI()->get('config');
            $modelNameSpace = $config['namespaces']['models'];
            $path = $modelNameSpace . $this->getModelName();
            $parents = array();

            $currentParent = $path::$parentModel;

            while ($currentParent) :
                $parents[] = $currentParent;
                $path = $modelNameSpace . $currentParent;
                $currentParent = $path::$parentModel;
            endwhile;
            $this->parentModels = $parents;
        }

        if (count($this->parentModels) == 0) {
            return false;
        }

        // reset name space if it was not asked for
        if (!$nameSpace) {
            $modelNameSpace = null;
        }

        $parents = array();
        foreach ($this->parentModels as $parent) {
            $parents[] = $modelNameSpace . $parent;
        }

        return $parents;
    }
}