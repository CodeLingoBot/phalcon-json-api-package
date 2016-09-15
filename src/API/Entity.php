<?php
namespace PhalconRest\API;

use Phalcon\Di;
use Phalcon\DI\Injectable;
use Phalcon\Mvc\Model\Relation as PhalconRelation;
use \PhalconRest\Util\HTTPException;
use \PhalconRest\Util\ValidationException;

/**
 * Pulls together one or more models to represent the REST resource(s)
 * Early work revolves around supporting API calls like...Model w/ Related Records
 * Loosely follows the Phalcon Model api...that is when entity performs a function
 * similar to a model, it attempts to mimic the function name and signatures
 */
class Entity extends Injectable
{

    /**
     * store a list of all active relationships
     * not just a list of all possible relationships
     *
     * @var PhalconRelation[]|Relation[]
     */
    public $activeRelations = null;

    /**
     * store the final JSON representation going to the server
     */
    public $restResponse;

    /**
     * store the name the endpoint should advertise
     * if nothing is defined, one will be auto detected
     *
     * @var string
     */
    public $endpointName = null;

    /**
     * keep a copy of the entity records PKID
     *
     * @var int
     */
    public $primaryKeyValue = null;

    /**
     * temporary value used to store the currently loaded database record
     * can be accessed from around the entity class
     *
     * @var array
     */
    protected $baseRecord = [];

    /**
     * store Phalcon lib for use throughout the class
     *
     * @var \Phalcon\Mvc\Model\MetaData\Memory
     */
    protected $metaData;

    /**
     * a searchHelper object used for when queries originate from HTTP requests
     *
     * @var SearchHelper
     */
    public $searchHelper = null;

    /**
     * store the total records found in a search (before limit)
     *
     * @var integer
     */
    private $recordCount = null;

    /**
     * relevant only for save function
     *
     * @var string insert | update
     */
    protected $saveMode = null;

    /**
     * the model that drives the entity
     *
     * @var BaseModel
     */
    protected $model;

    /**
     * store the query builder object used by the entity to pull data from the database
     *
     * @var QueryBuilder
     */
    private $queryBuilder;


    /**
     * hold a list of all related records that are to be fetched form the database and
     * related to their parent record
     *
     * takes the form of [ReferenceModelNameSpace'=>[various, requested, foreign_keys];
     * @var array
     */
    private $hasManyRegistry = [];

    /**
     * process injected model
     *
     * @param BaseModel $model
     * @param SearchHelper $searchHelper
     */
    function __construct(BaseModel $model, SearchHelper $searchHelper)
    {
        $di = Di::getDefault();
        $this->setDI($di);

        $this->queryBuilder = $di->get('queryBuilder', [$model, $searchHelper, $this]);

        // the primary model associated with with entity
        $this->model = $model;

        // a searchHelper, needed anytime we load an entity
        $this->searchHelper = $searchHelper;

        // where to store the final results?
        $this->restResponse = [];

        // hook to configure entity determined searchHelper defaults
        $this->configureSearchHelper();

        // load since it is nearly always needed
        $this->loadActiveRelationships();
    }

    /**
     * Hook intended to be overriden, that should execute anything related to {@link searchHelper}.
     */
    public function configureSearchHelper()
    {
    }

    /**
     * For a given search query, performs a find + loading of related records
     * @param mixed $suppliedParameters
     * @return array|false Returns false if nothing is found
     */
    public function find($suppliedParameters = null)
    {
        $baseRecords = $this->runSearch($suppliedParameters);

        // if we don't find a record, terminate with false
        if ($baseRecords === false) {
            return false;
        }

        // prep before processing records
        $this->restResponse[$this->model->getTableName()] = [];

        $foundSet = 0;
        if ($this->di->get('config')['application']['debugApp'] == true) {
            $timer = $this->di->get('stopwatch');
            $timer->lap('Gather Records');
        }
        foreach ($baseRecords as $baseResult) {
            // normalize results, pull out join fields and store in a class lvl variable
            $this->extractMainRow($baseResult);

            // hook for manipulating the base record before processing relationships
            $baseResult = $this->beforeProcessRelationships($baseResult);

            // store related records in restResponse or load for optimized DB queries
            $this->processRelationships($baseResult);

            // hook for manipulating the base record after processing relationships
            $this->afterProcessRelationships($baseResult);

            $this->pushRestResponse($this->model->getTableName(), $this->baseRecord, true);
            $foundSet++;
        }

        // no need to process when no records were found
        if ($foundSet > 0) {
            $this->processDelayedRelationships();
        }

        if (isset($timer)) {
            $timer->lap('Formatting Output');
        }
        // TODO single DB query for records related to main query
        $this->appendMeta($foundSet);
        return $this->restResponse;
    }

    /**
     * process all the SQL requests queued up while processing records but delayed
     */
    public function processDelayedRelationships()
    {
        $config = $this->getDI()->get('config');
        if (!array_deep_key($config, 'feature_flags.fastHasMany')) {
            // feature flag is disabled, nothing to do
            return;
        }

        foreach ($this->activeRelations as $relation) {
            $refType = $relation->getType();
            if ($refType == PhalconRelation::HAS_MANY) {
                // finally process a combined call for child records
                $relatedRecords = $this->getHasManyRecords($relation);
                $this->updateBaseRecords($relatedRecords, $relation);
                $this->updateRestResponse($relation->getTableName(), $relatedRecords);
            }
        }

    }

    /**
     * hook for manipulating the base record before processing relationships
     * this method is called from the find and findFirst methods
     *
     * @param mixed $baseResult
     * @return mixed $baseResult
     */
    public function beforeProcessRelationships($baseResult)
    {
        return $baseResult;
    }

    /**
     * hook for manipulating the base record after processing relatoinships.
     * this method is called from the find and findFirst methods
     *
     * will accept baseResult as param but there is no point in passing it back up since the real data is in a class level object
     *
     * @param mixed $baseResult
     */
    public function afterProcessRelationships($baseResult)
    {
    }

    /**
     * for a given ID, load a record including any related tables
     * such as employee+user, user addresses and user phones
     *
     * @param mixed $id The PKID for the record
     *
     * @return array|false an array record, otherwise false
     */
    public function findFirst($id)
    {
        // store for future reference
        $this->primaryKeyValue = $id;

        // prep for a special kind of search
        $this->searchHelper->entityLimit = 1;
        $searchField = $this->model->getPrimaryKeyName();
        $this->searchHelper->entitySearchFields = [$searchField => $id];

        $baseRecords = $this->runSearch();

        // if we don't find a record, terminate with false
        if ($baseRecords === false) {
            return false;
        }

        $foundSet = 0;
        if ($this->di->get('config')['application']['debugApp'] == true) {
            $timer = $this->di->get('stopwatch');
            $timer->lap('Gathering Records');
        }
        foreach ($baseRecords as $baseResult) {
            // normalize results, pull out join fields and store in a class lvl variable
            $this->extractMainRow($baseResult);

            // hook for manipulating the base record before processing relationships
            $baseResult = $this->beforeProcessRelationships($baseResult);

            // store related records in restResponse or load for optimized DB queries
            $this->processRelationships($baseResult);

            // hook for manipulating the base record after processing relationships
            $this->afterProcessRelationships($baseResult);

            $this->pushRestResponse($this->model->getTableName('singular'), $this->baseRecord);
            $foundSet++;
        }

        $this->processDelayedRelationships();

        if (isset($timer)) {
            $timer->lap('Formatting Output');
        }

        // no records found on a findFirst?
        // json api calls for a 404
        if ($foundSet == 0) {
            return false;
        }

        $this->appendMeta($foundSet);

        return $this->restResponse;
    }

    /**
     * add a few extra metrics as enabled by the system
     *
     * @param int $foundSet a count of the records matching api request
     */
    protected function appendMeta($foundSet)
    {
        // should we load pager information?
        if ($this->searchHelper->isPager) {
            if (!isset($this->restResponse['meta'])) {
                $this->restResponse['meta'] = [];
            }
            // calculate the number of "paged" records in total
            $this->restResponse['meta']['total_pages'] = ceil($this->recordCount / $this->searchHelper->getLimit());
            $this->restResponse['meta']['total_record_count'] = $this->recordCount;
            $this->restResponse['meta']['returned_record_count'] = $foundSet;

            $config = $this->getDI()->get('config');
            if ($config['application']['debugApp']) {
                $registry = $this->getDI()->get('registry');
                if (isset($registry->dbCount)) {
                    $this->restResponse['meta']['database_query_count'] = $registry->dbCount;
                }
                if (isset($registry->dbTimer)) {
                    $this->restResponse['meta']['database_query_timer'] = $registry->dbTimer . ' ms';
                }
            }
        }
    }

    /**
     * will run a search, but forks to either do a PHQL based query
     * or simple query depending on suppliedParameters
     *
     * TODO do we need to support suppliedParameter anymore?
     *
     * @param string $suppliedParameters
     * @return mixed
     */
    public function runSearch($suppliedParameters = null)
    {

        // hook before query in case entity needs to act
        $this->beforeQueryBuilderHook();

        // run a simple search if parameters are supplied,
        // this would only happen if another part of the app was calling this entity directly
        // not sure we need this, it might be better to work directly on the searchHelper?
        if (is_null($suppliedParameters)) {
            // construct using PHQL

            // run this once for the count
            // access hook after queryBuilder in case entity needs to act on the query
            $query = $this->queryBuilder->build('count');
            $query = $this->afterQueryBuilderHook($query);

            $result = $query->getQuery()->getSingleResult();
            $this->recordCount = intval($result->count);

            if (!$this->searchHelper->isCount) {
                // now run the real query
                // access hook after queryBuilder in case entity needs to act on the query
                $query = $this->queryBuilder->build();
                $query = $this->afterQueryBuilderHook($query);

                $result = $query->getQuery()->execute();
                return $result;
            } else {
                return [];
            }
        } else {
            // strip out column filter since Phalcon doesn't return a full object then
            if (isset($suppliedParameters['columns'])) {
                unset($suppliedParameters['columns']);
            }

            // send back the search results
            return $this->model->find($suppliedParameters);
        }
    }


    /**
     * for a given base record, build an array to represent a single row including merged tables
     * strip out extra merge rows and return a single result record
     *
     * @param mixed $baseRecord
     * @return void
     */
    public function extractMainRow($baseRecord)
    {
        $class = get_class($baseRecord);

        // basically check for parent records and pull them out
        if ($class == 'Phalcon\Mvc\Model\Row') {
            // hold the combined and normalized array of data fields
            $baseArray = [];
            $primaryModel = $this->model->getModelNameSpace();

            foreach ($baseRecord as $record) {
                $class = get_class($record);
                if ($primaryModel === $class) {
                    $baseArray = array_merge($this->loadAllowedColumns($record, false, false), $baseArray);
                    continue;
                }

                foreach ($this->activeRelations as $relation) {
                    $refType = $relation->getType();

                    if ($refType == 1) {
                        $baseArray = array_merge($this->loadAllowedColumns($record, false, false), $baseArray);
                    }
                }
            }
            $this->baseRecord = $baseArray;
        } else {
            $this->baseRecord = $this->loadAllowedColumns($baseRecord, false, false);
        }
    }

    /**
     * For a given record, load any related values. Runs inside {@link find()} and {@link findFirst()}.
     * @see processCustomRelationships()
     * @see processStandardRelationships()
     * @see $baseRecord
     * @param array|\Phalcon\Mvc\Model\Row $baseRecord the base record to decorate
     * @return bool Results are put together on {@link $baseRecord}
     */
    public function processRelationships($baseRecord)
    {
        // load primaryKeyValue
        $primaryKeyName = $this->model->getPrimaryKeyName();
        $this->primaryKeyValue = $this->baseRecord[$primaryKeyName];

        // process all loaded relationships by fetching related data
        foreach ($this->activeRelations as $relation) {
            // check if this relationship has been flagged for custom processing
            $relationOptions = $relation->getOptions();
            if (isset($relationOptions) && (array_key_exists('customProcessing', $relationOptions) && ($relationOptions['customProcessing'] === true))) {
                $this->processCustomRelationships($relation, $baseRecord);
            } else {
                $this->processStandardRelationships($relation, $baseRecord);
            }
        }
        return true;
    }

    /**
     * This method is stubbed out here so that it can be extended and used in local Entity file
     * to do custom processing for certain endpoints
     *
     * @param object $relation
     * @param array $baseRecord
     * @return boolean
     */
    protected function processCustomRelationships($relation, $baseRecord)
    {
        return true;
    }

    /**
     * Standard method for processing relationships
     * build an intermediate list of related records
     * then normalize them for inclusion in the final response
     *
     * @param Relation|PhalconRelation $relation
     * @param array $baseRecord
     * @throws HTTPException
     * @return mixed
     * @fixme this method contains some odd return points
     */
    protected function processStandardRelationships($relation, $baseRecord)
    {
        // store parentModels for later use
        $parentModels = $this->model->getParentModels(true);

        // the intermediate set of related records
        $relatedRecords = [];

        // store a copy of all related record (PKIDs)
        // this must be attached w/ the parent records for joining purposes
        $relatedRecordIds = null;

        if ($parentModels and in_array($relation->getReferencedModel(), $parentModels)) {
            // skip any parent relationships because they are merged into the main record
        } else {
            $refType = $relation->getType();
            $alias = $relation->getAlias();

            // figure out if we have a preferred alias
            if (isset($alias)) {
                $refModelName = $alias;
            } else {
                $refModelName = $relation->getModelName();
            }

            $config = $this->getDI()->get('config');
            // harmonize relatedRecords
            switch ($refType) {
                case PhalconRelation::BELONGS_TO:
                    // extract belongsTo record differently if it's already present in the original query
                    if (!array_deep_key($config, 'feature_flags.fastBelongsTo')) {
                        $relatedRecords = $this->getBelongsToRecord($relation);
                    } else {
                        // for simple belongsTo, pluck the related record out of base record since we know its in there
                        // some belongsTo have parent records, revert to the older style to get a complete record
                        if ($relation->getParent()) {
                            $relatedRecords = $this->getBelongsToRecord($relation);
                        } else {
                            $relatedRecords = $this->loadRelationRecords([$baseRecord->$refModelName], $relation);
                        }
                    }
                    break;

                case PhalconRelation::HAS_ONE:
                    // ignore hasOne since they are processed like a parent relation
                    // this means current logic will not merge in a parent's record for a hasOne relationship
                    // it's an edge case but should be supported in the future
                    break;

                case PhalconRelation::HAS_MANY_THROUGH:
                    $relatedRecords = $this->getHasManyToManyRecords($relation);
                    break;

                case PhalconRelation::HAS_MANY:
                    if (!array_deep_key($config, 'feature_flags.fastHasMany')) {
                        $relatedRecords = $this->getHasManyRecords($relation);
                    } else {
                        // register a future record request to be processed later
                        $this->registerHasManyRequest($relation);
                    }
                    break;

                default:
                    // wah!
                    throw new HTTPException('Unknown relationship submitted', 500, ['code' => '4984846846849494']);
            }

            // only normalize when some sort of resultset is returned, even an empty array
            if (isset($relatedRecords) && is_array($relatedRecords)) {
                return $this->normalizeRelatedRecords($baseRecord, $relatedRecords, $relation);
            }

            return true;
        }
    }

    /**
     * hook for an entity to act before queryBuilder runs
     * no query object supplied this is is before one is generated
     *
     * @return void
     */
    public function beforeQueryBuilderHook()
    {

    }

    /**
     * hook to allow for custom work to be done on the $query object before returning it
     *
     * @param $query
     * @return mixed
     */
    public function afterQueryBuilderHook($query)
    {
        return $query;
    }

    /**
     * Normalize the related records so they can be added into the response object
     *
     * @param array $baseRecord
     * @param array $relatedRecords
     * @param object $relation
     * @return boolean
     */
    protected function normalizeRelatedRecords($baseRecord, $relatedRecords, $relation)
    {
        $refType = $relation->getType();

        $refModelNameSpace = $relation->getReferencedModel();

        /** @var BaseModel $refModel */
        // store a copy of all related record (PKIDs)
        // this must be attached w/ the parent records for joining purposes
        $relatedRecordIds = null;
        $refModel = new $refModelNameSpace();
        $primaryKeyName = $refModel->getPrimaryKeyName();

        // save the PKID for each record returned
        if (count($relatedRecords) > 0) {
            // 1 = hasOne 0 = belongsTo 2 = hasMany
            switch ($refType) {
                // process hasOne records as well
                case PhalconRelation::HAS_ONE:
                    // do nothing w/ hasOne since these are auto merged into the main record
                    break;
                case PhalconRelation::BELONGS_TO:
                    // this doesn't seem right, why are they occasionally showing up inside an array?
                    if (isset($relatedRecords[$primaryKeyName])) {
                        $relatedRecordIds = $relatedRecords[$primaryKeyName];
                        // wrap in array so we can store multiple hasOnes from many different main records
                        $relatedRecords = [$relatedRecords];
                    } else {
                        $relatedRecordIds = $relatedRecords[0][$primaryKeyName];
                    }
                    break;

                default:
                    $relatedRecordIds = [];
                    foreach ($relatedRecords as $rec) {
                        $relatedRecordIds[] = $rec[$primaryKeyName];
                    }
                    break;
            }
        } else {
            $relatedRecordIds = null;
        }

        // we map table names to end point resource names and vice versa
        // regardless of relationship, the related records are returned as part of the end point resource name
        $this->updateRestResponse($relation->getTableName(), $relatedRecords);

        // add related record ids to the baseArray
        // this is how JSON API suggests that you related resources
        // will save nothing, a single value or an array

        // does this only run when working with hasMany?
        // belongsTo and hasOne are already in place, yes?
        if ($relatedRecordIds !== null) {
            if ($refType == PhalconRelation::HAS_MANY || $refType == PhalconRelation::HAS_MANY_THROUGH) {
                // populate the linked property or merge in additional records
                // attempt to store the name similar to the table name
                $name = $relation->getTableName('singular') . '_ids';
                $this->baseRecord[$name] = $relatedRecordIds;
            }
        }

        return true;
    }

    /**
     * integrate in a batch of related records to their baseRecord
     * tricky since the base record has already been written to the rest response...
     *
     * originally written to process a series of where IN records and attach to their parents via hasMany
     *
     * @param $relatedRecords
     * @param Relation|PhalconRelation $relation
     */
    private function updateBaseRecords($relatedRecords, $relation)
    {
        //process relatedRecords and stuff them into the rest response
        $refModelNameSpace = $relation->getReferencedModel();

        /** @var BaseModel $refModel */
        // store a copy of all related record (PKIDs)
        // this must be attached w/ the parent records for joining purposes
        $refModel = new $refModelNameSpace();
        $primaryKeyName = $refModel->getPrimaryKeyName();
        $foreignKeyName = $relation->getReferencedFields();

        // store a more friendly list of records by foreign_key
        $intermediateRows = [];
        foreach ($relatedRecords as $child) {
            $intermediateRows[$child[$foreignKeyName]][] = $child[$primaryKeyName];
        }

        // populate the linked property or merge in additional records
        // attempt to store the name similar to the table name
        $name = $relation->getTableName('singular') . '_ids';
        $modelTable = $this->model->getTableName();
        $restKey = isset($this->restResponse[$modelTable]) ? $modelTable : $this->model->getTableName('singular');
        foreach ($this->restResponse[$restKey] as &$record) {
            $record[$name] = isset($intermediateRows[$record['id']]) ? $intermediateRows[$record['id']] : [];
        }
    }


    /**
     * load an array of records into the restResponse
     *
     * @param string $table the table name where the records originated
     * @param array $records usually related records, but could side load just about any records to an api response
     * @return void
     */
    protected function updateRestResponse($table, array $records)
    {
        if (!isset($this->restResponse[$table])) {
            $this->restResponse[$table] = $records;
        } else {
            foreach ($records as $newRecord) {
                $this->pushRestResponse($table, $newRecord);
            }
        }
    }

    /**
     * push a single record into the rest response
     * check for duplicates
     *
     * @param string $table the table name where the records originated
     * @param array $newRecord usually related records, but could side load just about any records to an api response
     * @param boolean $skipDuplicateCheck should the function skip checking for duplicates and push it into the rest response directly?
     *  this can be useful when you know the chance of duplicates is close to zero
     * @return void
     */
    protected function pushRestResponse($table, array $newRecord, $skipDuplicateCheck = false)
    {
        if (!isset($this->restResponse[$table])) {
            $this->restResponse[$table][] = $newRecord;
        } else {
            if ($skipDuplicateCheck == false) {
                // check that this array doesn't already exist, otherwise push it into the stack
                foreach ($this->restResponse[$table] as $record) {
                    // if the number of keys differ...it's different
                    if (count($record) === count($newRecord)) {
                        // try two different ways to compare records when checking for duplicates
                        $match = true;
                        foreach (array_keys($newRecord) as $key) {
                            if ($newRecord[$key] !== $record[$key]) {
                                $match = false;
                            }
                        }
                        // all keys match, bug out!
                        if ($match) {
                            return;
                        }

                        // this appears to be slower
//                    if (serialize($newRecord) === serialize($record)) {
//                        return;
//                    }
                    }
                }
            }
            $this->restResponse[$table][] = $newRecord;
        }
    }


    /**
     * extract only approved fields from a resultset
     * this works with each resultset's model to get a list of allowed columns
     * hence the similar method signature
     *
     * @param \PhalconRest\API\BaseModel $resultSet
     * @param bool $nameSpace
     * @param bool $includeParent
     * @return array
     */
    protected function loadAllowedColumns(BaseModel $resultSet, $nameSpace = true, $includeParent = true)
    {
        $record = [];
        $allowedFields = $resultSet->getAllowedColumns($nameSpace, $includeParent);
        foreach ($allowedFields as $field) {
            if (isset($resultSet->$field)) {
                $record[$field] = $resultSet->$field;
            } else {
                // error, field doesn't exist on resultSet!
                // don't set to null, just leave it alone
                $record[$field] = null;
            }
        }
        return $record;
    }

    /**
     * built for hasMany relationships
     * in cases where the related record itself refers to a parent record,
     * write a custom query to load the related record including it's parent
     *
     * depends on the existance of a primaryKeyValue
     *
     * @param Relation|PhalconRelation $relation
     * @return array
     */
    protected function getHasManyRecords(Relation $relation)
    {
        $query = $this->buildRelationQuery($relation);

        $config = $this->getDI()->get('config');
        if (!array_deep_key($config, 'feature_flags.fastHasMany')) {
            // feature flag is disabled, only looking for one parent record
            // determine the key to search against
            $field = $relation->getFields();
            if (isset($this->baseRecord[$field])) {
                $fieldValue = $this->baseRecord[$field];
            } else {
                // fall back to using the primaryKeyValue
                $fieldValue = $this->primaryKeyValue;
            }
            $fieldName = $relation->getReferencedModel() . '.' . $relation->getReferencedFields();
            $query->where("$fieldName = \"$fieldValue\"");
        } else {
            // feature flag is enabled, pulling from register instead
            $foreign_keys = array_unique($this->hasManyRegistry[$relation->getReferencedModel()]);
            //name space the referenced field otherwise you might get ambigious errors

            $query->inWhere($relation->getReferencedModel() . '.' . $relation->getReferencedFields(), $foreign_keys);
        }

        $result = $query->getQuery()->execute();
        return $this->loadRelationRecords($result, $relation);
    }

    /**
     * store away a request for a record in a child table
     * @param Relation|PhalconRelation $relation
     */
    protected function registerHasManyRequest(Relation $relation)
    {
        // determine the key to search against
        $field = $relation->getFields();
        if (isset($this->baseRecord[$field])) {
            $fieldValue = $this->baseRecord[$field];
        } else {
            // fall back to using the primaryKeyValue
            $fieldValue = $this->primaryKeyValue;
        }
        $this->hasManyRegistry[$relation->getReferencedModel()][] = $fieldValue;
    }

    /**
     * built for belongsTo relationships
     * in cases where the related record itself refers to a parent record,
     * write a custom query to load the related record including it's parent
     *
     * @param Relation|PhalconRelation $relation
     * @return array
     */
    private function getBelongsToRecord(Relation $relation)
    {
        $query = $this->buildRelationQuery($relation);
        $referencedField = $relation->getReferencedFields();
        $foreignKey = $relation->getFields();

        // can take a shortcut here,
        // if the related record has already been loaded, than return empty array
        $tableName = $relation->getTableName();
        $foreignKeyValue = $this->baseRecord[$foreignKey];

        if (isset($this->restResponse[$tableName]) and count($this->restResponse[$tableName]) > 0) {
            // figure out how to best refer to the newly loaded field
            // will check for a reference field first, but if that is blocked....
            // try the pkid or even worse, just try "id" as a last resort
            $matchField = 'id';

            if (isset($this->restResponse[$tableName][0][$referencedField])) {
                $matchField = $referencedField;
            } elseif (isset($this->restResponse[$tableName][0][$relation->getPrimaryKeyName()])) {
                $matchField = $relation->getPrimaryKeyName();
            }

            foreach ($this->restResponse[$tableName] as $row) {
                if ($row[$matchField] == $foreignKeyValue) {
                    return [];
                }
            }
        }
        // query uses model prefix to avoid ambiguous queries
        $query->where("{$relation->getReferencedModel()}.{$referencedField} = \"{$this->baseRecord[$foreignKey]}\"");
        $result = $query->getQuery()->execute();
        return $this->loadRelationRecords($result, $relation);
    }

    /**
     * load the query object for a hasManyToMany relationship
     * build most of the joins manually since by reference relationship in the model isn't working so well
     * this support joins to distant tables with parent models
     *
     * @param Relation|PhalconRelation $relation
     * @return array
     */
    protected function getHasManyToManyRecords($relation)
    {
        $refModelNameSpace = $relation->getReferencedModel();
        $intermediateModelNameSpace = $relation->getIntermediateModel();

        // determine the key to search against
        $field = $relation->getFields();
        $referencedField = $relation->getReferencedFields();
        $intermediateFields = $relation->getIntermediateReferencedFields();

        $config = $this->getDI()->get('config');
        $modelNameSpace = $config['namespaces']['models'];
        $mm = $this->getDI()->get('modelsManager');

        /** @var \Phalcon\Mvc\Model\Query\Builder $query */
        $query = $mm->createBuilder()
            ->from($intermediateModelNameSpace)
            ->join($refModelNameSpace, $refModelNameSpace . ".$referencedField = " . $intermediateModelNameSpace . ".$intermediateFields");

        $columns = [];

        // join in parent record if one is detected
        $parentName = $relation->getParent();
        if ($parentName) {
            /** @var BaseModel $parentModel */
            // load parent model
            $parentModelNameSpace = $modelNameSpace . $parentName;
            $parentModel = new $refModelNameSpace();
            // load reference relationship
            $parentRelationship = $parentModel->getRelation($parentName);

            $columns[] = "$parentModelNameSpace.*";
            $query->join($parentModelNameSpace,
                "$parentModelNameSpace." . $parentRelationship->getReferencedFields() . " = $refModelNameSpace." . $parentRelationship->getFields(),
                $parentModelNameSpace);
        }

        // Load the main record field at the end, so they are not overwritten
        $columns[] = $refModelNameSpace . ".*, " . $intermediateModelNameSpace . ".*";
        $query->columns($columns);

        if (isset($this->baseRecord[$field])) {
            $fieldValue = $this->baseRecord[$field];
        } else {
            // fall back to using the primaryKeyValue
            $fieldValue = $this->primaryKeyValue;
        }

        $whereField = $intermediateModelNameSpace . '.' . $relation->getIntermediateFields();
        $query->where("{$whereField} = \"$fieldValue\"");
        $result = $query->getQuery()->execute();
        return $this->loadRelationRecords($result, $relation);
    }

    /**
     * utility shared between getBelongsToRecord and getHasManyRecords
     *
     * @param Relation|PhalconRelation $relation
     * @return object
     */
    private function buildRelationQuery(Relation $relation)
    {
        /** @var \Phalcon\Mvc\Model\Query\Builder $query */
        $refModelNameSpace = $relation->getReferencedModel();
        $mm = $this->getDI()->get('modelsManager');
        $query = $mm->createBuilder()->from($refModelNameSpace);
        $columns = [];

        // hasOnes are auto merged
        // todo should this be controlled by entityWith?
        $list = $relation->getHasOnes();
        foreach ($list as $model) {
            $columns[] = $model . '.*';
            $query->leftJoin($model);
        }

        // Load the main record field at the end, so they are not overwritten
        $columns[] = $refModelNameSpace . ".*";
        $query->columns($columns);

        return $query;
    }

    /**
     * utility shared between getBelongsToRecord and getHasManyRecords
     * will process a related record result set and return
     * one or more individual record arrays in a larger array
     *
     * @param array $result
     * @param Relation|PhalconRelation $relation
     * @return array
     */
    protected function loadRelationRecords($result, Relation $relation)
    {
        $relatedRecords = []; // store all related records
        foreach ($result as $relatedRecord) {
            // when a related record contains hasOne or a parent, merge in those fields as part of side load response
            $parent = $relation->getParent();
            $classType = get_class($relatedRecord);
            if (($parent AND $classType == 'Phalcon\Mvc\Model\Row') or $classType == 'Phalcon\Mvc\Model\Row') {
                $relatedRecArray = [];
                // process records that include joined in parent records
                foreach ($relatedRecord as $rec) {
                    // filter manyHasMany differently than other relationships
                    if ($relation->getType() == PhalconRelation::HAS_MANY_THROUGH) {
                        // only interested in the "end" relationship, not the intermediate
                        $intermediateModelNameSpace = $relation->getIntermediateModel();
                        if ($intermediateModelNameSpace == get_class($rec)) {
                            continue;
                        }
                    }
                    // we don't as for parent fields here because they are already included in the complex $result
                    $newFields = $this->loadAllowedColumns($rec, false, false);
                    $relatedRecArray = array_merge($newFields, $relatedRecArray);
                }
            } else {
                // reset for each run
                $relatedRecArray = false;

                // let's inspect this for basic validity and ignore out empty records
                // think of a join on optional table
                $primaryKeyName = $relatedRecord->getPrimaryKeyName();
                if (isset($relatedRecord->$primaryKeyName) AND $relatedRecord->$primaryKeyName != null) {
                    $relatedRecArray = $this->loadAllowedColumns($relatedRecord, false, false);
                }
            }
            // push if a valid record is found
            if ($relatedRecArray) {
                $relatedRecords[] = $relatedRecArray;
            }
        }
        return $relatedRecords;
    }

    /**
     * for a given set of relationships,
     * load them into the entity so find* functions return all requested related data
     *
     * make this a getter? It doesn't actually return the array, so keeping as load
     *
     * always load parent model(s)
     *
     * auto = do nothing
     * all = load all possible relationships
     * csv,list = load only these relationships
     *
     * @return bool
     */
    final public function loadActiveRelationships()
    {
        // no need to run this multiple times
        if (!is_null($this->activeRelations)) {
            return null;
        }

        $this->activeRelations = [];
        $requestedRelationships = $this->searchHelper->getWith();
        $parentModels = $this->model->getParentModels(false);
        $modelRelationships = $this->model->getRelations();

        $all = false; // load all relationships?

        // process the private array of relationships
        switch ($requestedRelationships) {
            case 'none':
                $all = false;
                // gotta load parents if there are any
                $requestedRelationships = $parentModels ? $parentModels : [];
                break;
            case 'all':
                $all = true;
                break;
            // expect & process a csv string
            default:
                // expect csv list or simple string
                // user_addrs,user_phones
                $requestedRelationships = explode(',', strtolower($requestedRelationships));
                // include parents if there are any
                if ($parentModels) {
                    $requestedRelationships = array_merge($parentModels, $requestedRelationships);
                }
                break;
        }

        //FIXME: there's a lot of repeated code here. Simplify this!
        if ($all) {
            // load all defined relationships regardless of what was requested
            foreach ($modelRelationships as $relation) {
                $modelName = $relation->getModelName();
                $aliasName = $relation->getAlias();

                // figure out if we have a preferred alias
                if ($aliasName) {
                    $this->activeRelations[$aliasName] = $relation;
                } else {
                    $this->activeRelations[$modelName] = $relation;
                }
            }
        } else {
            //
            foreach ($requestedRelationships as $requestedRelationship) {
                $matchFound = false;
                foreach ($modelRelationships as $relation) {
                    $modelName = $relation->getModelName();
                    $aliasName = $relation->getAlias();

                    // make sure the relationship is approved either as the table name, model name or ALL
                    // table names because end point resources = table names
                    // model name because some auto generated relationships use this name instead
                    // alias is used to STORE the active relationship in case multiple relationships point to the same model
                    // but it is not a valid way for a client to request data
                    if ($requestedRelationship == $aliasName) {
                        $matchFound = true;
                        // figure out if we have a preferred alias
                        if ($aliasName) {
                            $this->activeRelations[$aliasName] = $relation;
                        } else {
                            $this->activeRelations[$modelName] = $relation;
                        }
                        break;
                    }
                }

                if (!$matchFound) {
                    foreach ($modelRelationships as $relation) {
                        $tableName = $relation->getTableName();
                        $modelName = $relation->getModelName();
                        $aliasName = $relation->getAlias();

                        if ($requestedRelationship == $tableName) {
                            $matchFound = true;
                            // figure out if we have a preferred alias
                            if ($aliasName) {
                                $this->activeRelations[$aliasName] = $relation;
                            } else {
                                $this->activeRelations[$modelName] = $relation;
                            }
                            break;
                        }
                    }
                } else {
                    continue;
                }

                if (!$matchFound) {
                    // if you are still here, then you have a problem!
                    // a relation ship was requested that doesn't actually exist
                }
            }
        }

        $this->afterloadActiveRelationships();

        return true;
    }

    /**
     * hook to be called after loading active relationships
     *
     * @return bool
     */
    protected function afterLoadActiveRelationships()
    {
        return true;
    }

    /**
     * remove a complete entity based on a supplied primary key
     * TODO how to handle deleting from a leaf node, check this->parentModel
     * currently this logic depends on the SQL cascade rule to do the heavy lifting
     *
     * @param int $id
     * @throws HTTPException
     * @return boolean
     */
    public function delete($id)
    {
        /** @var BaseModel $primaryModelName */
        // $inflector = new Inflector();
        $primaryModelName = $this->model->getModelNameSpace();
        $modelToDelete = $primaryModelName::findFirst($id);
        $this->beforeDelete($modelToDelete);

        if ($modelToDelete != false) {
            // attempt delete run gold leader!
            if ($modelToDelete->delete() == false) {
                // store error messages
                $messageBag = $this->getDI()->get('messageBag');
                foreach ($this->model->getMessages() as $message) {
                    $messageBag->set($message->getMessage());
                }
                throw new HTTPException("Error deleting record #$id.", 500, ['code' => '66498419846816']);
            }
        } else {
            // no record found to delete
            throw new HTTPException("Could not find record #$id to delete.", 404, [
                'dev' => "No record was found to delete",
                'code' => '2343467699'
            ]); // Could have link to documentation here.
        }

        $this->afterDelete($modelToDelete);

        return true;
    }

    /**
     * hook to be run before an entity is deleted
     * make it easier to extend default delete logic
     *
     * @param mixed $model the record to be deleted
     */
    public function beforeDelete($model)
    {
        // extend me in child class
    }

    /**
     * hook to be run after an entity is deleted
     * make it easier to extend default delete logic
     *
     * @param mixed $model the record that was just removed
     */
    public function afterDelete($model)
    {
        // extend me in child class
    }

    /**
     * hook to be run before an entity is saved make it easier to extend default save logic
     *
     * @param object $object the data submitted to the server
     * @param int|null $id the pkid of the record to be updated, otherwise null on inserts
     * @return object $object
     */
    public function beforeSave($object, $id = null)
    {
        // extend me in child class
        return $object;
    }

    /**
     * hook to be run after an entity is saved
     * make it easier to extend default save logic
     *
     * @param mixed $object the data submitted to the server
     * @param int|null $id the pkid of the record to be updated or inserted
     */
    public function afterSave($object, $id)
    {
        // extend me in child class
    }

    /**
     * hook to be run after an entity is saved
     * and relationships have been processed
     *
     * @param mixed $object the data submitted to the server
     * @param int|null $id the pkid of the record to be updated or inserted
     */
    public function afterSaveRelations($object, $id)
    {
        // extend me in child class
    }

    /**
     * attempt to add/update a new entity
     * watch $id to determine if update or insert
     * built to accommodate saving records w/ parent tables (hasOne)
     *
     * @param mixed $formData the data submitted to the server
     * @param int $id the pkid of the record to be updated, otherwise null on inserts
     * @return int the PKID of the record in question
     */
    public function save($formData, $id = NULL)
    {
        // $inflector = new Inflector();

        // check if inserting a new record and account for any parent records
        if (is_null($id)) {
            $this->saveMode = 'insert';
            // pre-save hook placed after saveMode
            $formData = $this->beforeSave($formData, $id);
            // load a model including potential parents
            $primaryModel = $this->loadParentModel($this->model, $formData);
        } else {
            // update existing record
            $this->saveMode = 'update';

            // pre-save hook placed after saveMode
            $formData = $this->beforeSave($formData, $id);

            // make sure that the PKID is always stored in the formData
            $name = $this->model->getPrimaryKeyName();
            $formData->$name = $id;

            $this->primaryKeyValue = $id;

            // need parent logic here
            $model = $this->model;
            $primaryModel = $model::findFirst($id);
            $primaryModel = $this->loadParentModel($primaryModel, $formData);

            // // TODO this only works with 1 parent so far....
            // $parentModelName = $model::$parentModel;
            // if ($parentModelName) {
            // $config = $this->getDI()->get('config');
            // $modelNameSpace = $config['namespaces']['models'];
            // $parentNameSpace = $modelNameSpace . $parentModelName;
            // $parentModel = $parentNameSpace::findFirst($id);
            // $primaryModel = $this->loadModelValues($parentModel, $formData);
            // }
        }

        $result = $this->simpleSave($primaryModel);

        // if still blank, pull from recently created $result
        if (is_null($id)) {
            $this->primaryKeyValue = $id = $result;
        }

        // post save hook that is called before relationships have been saved
        $this->afterSave($formData, $id);

        // post save hook that is called after all relations have been saved as well
        $this->afterSaveRelations($formData, $id);

        $this->saveMode = null; // revert since save is finished
        return $this->primaryKeyValue;
    }

    /**
     * For a given model, load the parent if it exists;
     * Returns the final definitive parent model, along with loading client submitted data into each model
     *
     * @param BaseModel $model
     * @param object $object
     * @return object|bool $model Returns false if first argument is invalid (false?)
     */
    public function loadParentModel($model, $object)
    {

        // invalid first param, return false though it won't do much good
        if ($model === false) {
            return false;
        }

        if ($model::$parentModel != false) {
            /** @var BaseModel $parentModel */
            $config = $this->getDI()->get('config');
            $modelNameSpace = $config['namespaces']['models'];
            $parentNameSpace = $modelNameSpace . $model::$parentModel;
            $parentModel = new $parentNameSpace();
            $finalModel = $this->loadParentModel($parentModel, $object); //FIXME: sounds useless?

            if ($this->saveMode == 'update') {
                $primaryKey = $model->getPrimaryKeyName();
                $finalModel = $parentModel::findFirst($model->$primaryKey);
            } else {
                $finalModel = $this->loadParentModel($parentModel, $object);
            }

            // don't forget to load the child model values and mount into parent model
            $childModel = $this->loadModelValues($model, $object);
            $childModelName = $model->getModelName();
            $finalModel->$childModelName = $childModel;
        } else {
            $finalModel = $model;
        }

        // run object data through the model
        return $this->loadModelValues($finalModel, $object);
    }

    /**
     * load object data into the current model
     * only include specific known fields
     * will also include block fields since it expects there to be blocked at the controller
     *
     * @param BaseModel $model
     * @param object $formData
     *
     * @return BaseModel a model loaded with all relevant data from the object
     */
    public function loadModelValues($model, $formData)
    {
        // loop through nearly all known fields and save matches
        $metaData = $this->getDI()->get('memory');
        // use a colMap to prepare for save
        $colMap = $metaData->getColumnMap($model);
        if (is_null($colMap)) {
            // but if it isn't present, fall back to attributes
            $colMap = $metaData->getAttributes($model);
        }

        foreach ($colMap as $key => $label) {
            if (property_exists($formData, $label)) {
                // odd because $key shows up on model while $label doesn't
                // but $label WORKS and $key doesn't
                // must be some magic method property stuff
                $model->$label = $formData->$label;
            }
        }

        return $model;
    }

    /**
     * save a model and collect any error messages that may be returned
     * return the model PKID whether insert or update
     *
     * @param BaseModel $model
     * @throws ValidationException
     * @return int
     */
    function simpleSave($model)
    {
        $result = $model->save();
        // if the save failed, gather errors and return a validation failure
        if ($result == false) {
            throw new ValidationException("Validation Errors Encountered", array(
                'code' => '7894181864684',
                'dev' => 'entity->simpleSave failed to save model'
            ), $model->getMessages());
        }
        return $model->getPrimaryKeyValue();
    }
}
