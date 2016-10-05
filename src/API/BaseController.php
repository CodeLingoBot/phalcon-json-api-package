<?php
namespace PhalconRest\API;

use Phalcon\DI;
use Phalcon\Mvc\Controller;
use Phalcon\Mvc\Model\Relation as PhalconRelation;
use PhalconRest\Exception\HTTPException;

/**
 * \Phalcon\Mvc\Controller has a final __construct() method, so we can't
 * extend the constructor (which we will need for our RESTController).
 * Thus we extend DI\Injectable instead.
 *
 *
 * Responsible for handling various REST requests
 * Will load the correct model and entity and perform the correct action
 */
class BaseController extends Controller
{

    /**
     * Store the default entity here
     *
     * @var \PhalconRest\API\Entity
     */
    protected $entity;

    /**
     * Store the default model here
     *
     * @var \PhalconRest\API\BaseModel
     */
    protected $model;

    /**
     * The name of the controller, derived from inflection
     *
     * @var string
     */
    public $singularName;

    /**
     * Plural version of controller name. Used for Ember-compatible REST returns
     *
     * @var string
     */
    public $pluralName;

    /**
     * Includes the default Dependency Injector and loads the Entity.
     */
    public function onConstruct()
    {
        $di = DI::getDefault();
        $this->setDI($di);
        // initialize entity and set to class property (doing the same to the model property)
        $this->getEntity();
    }

    /**
     * Load a default model unless one is already in place
     * return the currently loaded model
     *
     * @param string|bool $modelNameString
     * @return BaseModel
     */
    public function getModel($modelNameString = false)
    {
        if ($this->model == false) {
            $config = $this->getDI()->get('config');
            // auto load model so we can inject it into the entity
            if (!$modelNameString) {
                $modelNameString = $this->getControllerName();
            }

            $modelName = $config['namespaces']['models'] . $modelNameString;
            $this->model = new $modelName($this->di);
        }
        return $this->model;
    }

    /**
     * Load an empty SearchHelper instance. Useful place to override its behavior.
     * @return SearchHelper
     */
    public function getSearchHelper()
    {
        return new SearchHelper();
    }

    /**
     * Load a default entity unless one is already in place
     * return the currently loaded entity
     *
     * @see $entity
     * @return \PhalconRest\API\Entity
     */
    public function getEntity()
    {
        if ($this->entity == false) {
            $config = $this->getDI()->get('config');
            $model = $this->getModel();
            $searchHelper = $this->getSearchHelper();
            $entity = $config['namespaces']['entities'] . $this->getControllerName('singular') . 'Entity';
            $entity = new $entity($model, $searchHelper);
            $this->entity = $this->configureEntity($entity);
        }
        return $this->entity;
    }

    /**
     * In order that the controller has access during the getSearchHelper
     * to configure the entity, the controller needs to implement
     * this method to override the functionality
     * @param  \PhalconRest\API\Entity $entity
     * @return \PhalconRest\API\Entity $entity
     */
    public function configureEntity($entity)
    {
        return $entity;
    }

    /**
     * get the controllers singular or plural name
     *
     * @param string $type
     * @return string|bool
     */
    public function getControllerName($type = 'plural')
    {
        if ($type == 'singular') {
            // auto calc if not already set
            if ($this->singularName == NULL) {
                $className = get_called_class();
                $config = $this->getDI()->get('config');
                $className = str_replace($config['namespaces']['controllers'], '', $className);
                $className = str_replace('Controller', '', $className);
                $this->singularName = $className;
            }
            return $this->singularName;
        } elseif ($type == 'plural') {
            // auto calc most common plural
            if ($this->pluralName == NULL) {
                // this could be better, just adding an s by default
                $this->pluralName = $this->getControllerName('singular') . 's';
            }
            return $this->pluralName;
        }

        // todo throw error here
        return false;
    }

    /**
     * catches incoming requests for groups of records
     *
     * @return \PhalconRest\Result\Result
     */
    public function get()
    {
        return $this->entity->find();
    }

    /**
     * run a limited query for one record
     * bypass nearly all normal search params and just search by the primary key
     *
     * special handling if no matching results are found
     *
     * @param int $id
     * @throws HTTPException
     * @return \PhalconRest\Result\Result
     */
    public function getOne($id)
    {
        $result = $this->entity->findFirst($id);
        if ($result->countResults() == 0) {
            // This is bad. Throw a 500. Responses should always be objects.
            throw new HTTPException('Resource not available.', 404, array(
                'dev' => 'The resource you requested is not available.',
                'code' => '43758093745021'
            ));
        } else {
            return $result;
        }
    }

    /**
     * Attempt to save a record from POST
     * This should be saving a new record
     *
     * @throws HTTPException
     * @return mixed return valid Apache code, could be an error, maybe not
     * @throws HTTPException
     */
    public function post()
    {
        $request = $this->getDI()->get('request');
        $post = $this->mungeSubmittedData($request->getJson());

        if (!$post) {
            throw new HTTPException("There was an error adding new record.  Missing POST data.", 400, array(
                'dev' => "Invalid data posted to the server",
                'code' => '568136818916816'
            ));
        }

        // filter out any block columns from the posted data
        $blockFields = $this->model->getBlockColumns();
        foreach ($blockFields as $key => $value) {
            unset($post->$value);
        }

        $post = $this->beforeSave($post);
        // This record only must be created
        $id = $this->entity->save($post);
        $this->afterSave($post, $id);

        // now fetch the record so we can return it
        $result = $this->entity->findFirst($id);

        if ($result->countResults() == 0) {
            // This is bad. Throw a 500. Responses should always be objects.
            throw new HTTPException("There was an error retrieving the newly created record.", 500, array(
                'dev' => 'The resource you requested is not available after it was just created',
                'code' => '1238510381861'
            ));
        } else {
            return $result;
        }
    }

    /**
     * Pass through to entity so it can perform extra logic if needed
     * most of the time...
     *
     * @param int $id
     * @return mixed return valid Apache code, could be an error, maybe not
     */
    public function delete($id)
    {
        $this->beforeDelete($id);
        $this->entity->delete($id);
        $this->afterDelete($id);
    }

    /**
     * read in a resource and update it
     *
     * @param int $id
     * @throws HTTPException
     * @return multitype:string
     */
    public function put($id)
    {
        $request = $this->getDI()->get('request');
        // load up the expected object based on the controller name
        $put = $this->mungeSubmittedData($request->getJson());

        if (!$put) {
            throw new HTTPException("There was an error updating an existing record.", 500, array(
                'dev' => "Invalid data posted to the server",
                'code' => '568136818916816'
            ));
        }

        // filter out any block columns from the posted data
        $blockFields = $this->model->getBlockColumns();
        foreach ($blockFields as $key => $value) {
            unset($put->$value);
        }

        $put = $this->beforeSave($put, $id);
        $id = $this->entity->save($put, $id);
        $this->afterSave($put, $id);

        // reload record so we can return it
        $result = $this->entity->findFirst($id);

        if ($result->countResults() == 0) {
            // This is bad. Throw a 500. Responses should always be objects.
            throw new HTTPException("There was an error retrieving the newly created record.", 500, array(
                'dev' => 'The resource you requested is not available after it was just created',
                'code' => '1238510381861'
            ));
        } else {
            return $result;
        }
    }


    /**
     * will disentangle the mess JSON API submits down to something our API can work with
     *
     * @param $post
     * @return mixed
     * @throws HTTPException
     */
    public function mungeSubmittedData($post)
    {
        // munge a bit so it works for internal data handling
        if (!isset($post->attributes)) {
            // error here, all posts require an attributes tag
            throw new HTTPException("The API received a malformed API request", 400, [
                'dev' => 'Bad or incomplete attributes property submitted to the api',
                'code' => '894168146168168168161'
            ]);
        } else {
            $data = $post->attributes;
        }

        if (isset($post->id)) {
            $data->id = $post->id;
        }

        // pull out relationships and convert to simple FKs
        if (isset($post->relationships)) {
            // go through model relationships and look for foreign keys
            $modelRelations = $this->model->getRelations();
            foreach ($modelRelations as $relation) {

                switch ($relation->getType()) {
                    case PhalconRelation::HAS_ONE:
                    case PhalconRelation::BELONGS_TO:
                        // pull from singular
                        $name = $relation->getTableName('singular');
                        if (isset($post->relationships->$name)) {
                            $fk = $relation->getFields();
                            if (isset($post->relationships->$name->data->id)) {
                                $data->$fk = $post->relationships->$name->data->id;
                            } else {
                                // A bad or incomplete relationship record was submitted
                                // this isn't always an error, it might be that an empty relationship was submitted

                                // throw new HTTPException("An error occurred saving this record.", 400, [
                                //    'dev' => 'Bad or incomplete relationship record posted to the api.',
                                //    'code' => '4894681316189'
                                // ]);
                            }
                        }
                        break;

                    case PhalconRelation::HAS_MANY:
                        // pull plural?

                        break;
                    default:

                        break;
                }
            }
        }

        return $data;
    }

    /**
     * hook to be run before a controller calls it's save action
     * make it easier to extend default save logic
     *
     * @param object $object the data submitted to the server
     * @param int|null $id the pkid of the record to be updated, otherwise null on inserts
     * @return object
     */
    public function beforeSave($object, $id = null)
    {
        // extend me in child class
        return $object;
    }

    /**
     * hook to be run after a controller completes it's save logic
     * make it easier to extend default save logic
     *
     * @param object $object the data submitted to the server (not a model)
     * @param int|null $id the pkid of the record to be updated or inserted
     */
    public function afterSave($object, $id)
    {
        // extend me in child class
    }

    /**
     * hook to be run before a controller performs delete logic
     * make it easier to extend default delete logic
     *
     * @param int $id the record to be deleted
     */
    public function beforeDelete($id)
    {
        // extend me in child class
    }

    /**
     * hook to be run after a controller performs delete logic
     * make it easier to extend default delete logic
     *
     * @param int $id the id of the record that was just removed
     */
    public function afterDelete($id)
    {
        // extend me in child class
    }

    /**
     * alias for PUT
     *
     * @param int $id
     * @return \PhalconRest\Result\Result
     */
    public function patch($id)
    {
        // route though PUT logic
        return $this->put($id);
    }

    /**
     * Provides a base CORS policy for routes like '/users' that represent a Resource's base url
     * Origin is allowed from all urls.
     * Setting it here using the Origin header from the request
     * allows multiple Origins to be served. It is done this way instead of with a wildcard '*'
     * because wildcard requests are not supported when a request needs credentials.
     *
     * @return true
     */
    public function optionsBase()
    {
        $response = $this->getDI()->get('response');

        $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, HEAD');
        $response->setHeader('Access-Control-Allow-Origin', $this->getDI()->get('request')->getHeader('Origin'));
        $response->setHeader('Access-Control-Allow-Credentials', 'true');
        $response->setHeader('Access-Control-Allow-Headers', "origin, x-requested-with, content-type");
        $response->setHeader('Access-Control-Max-Age', '86400');
        return true;
    }

    /**
     * Provides a CORS policy for routes like '/users/123' that represent a specific resource
     *
     * @return true
     */
    public function optionsOne()
    {
        $response = $this->getDI()->get('response');
        $response->setHeader('Access-Control-Allow-Methods', 'GET, PUT, PATCH, DELETE, OPTIONS, HEAD');
        $response->setHeader('Access-Control-Allow-Origin', $this->getDI()->get('request')->getHeader('Origin'));
        $response->setHeader('Access-Control-Allow-Credentials', 'true');
        $response->setHeader('Access-Control-Allow-Headers', "origin, x-requested-with, content-type");
        $response->setHeader('Access-Control-Max-Age', '86400');
        return true;
    }
}