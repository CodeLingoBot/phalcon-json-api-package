<?php
namespace PhalconRest\Authentication;

use Phalcon\DI\Injectable;
use Phalcon\Text;

/**
 * provide a standard user object fed into the authenticator
 * provides several functions that are called post authentication
 * thus providing applications with a way to inject their own behavior
 *
 * @author jjenkins
 *
 */
class UserProfile extends Injectable
{
    /**
     * holds the user identifier, populated by child application
     * @var string
     */
    public $userName;

    /**
     * store an authenticated user's token
     *
     * @var string
     *
     */
    public $token;

    public $expiresOn;

    /**
     * create a fresh token each time a new user profile is created
     */
    function __construct()
    {
    }

    /**
     * issue a generic token
     * replace with your own logic
     *
     * @return string
     */
    public function generateToken()
    {
        return Text::random(Text::RANDOM_ALNUM, 64);
    }

    /**
     * issue an expiration datetime stamp or replace with your own logic
     */
    public function generateExpiration()
    {
        $date = new \DateTime();
        $date->add(new \DateInterval('PT1H'));
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * load a full userProfile object based on a provided token
     *
     * @param array $search $key=>$value pairs
     * @return boolean Was profile loaded?
     */
    public function loadProfile($search)
    {
        // replace me with application specific logic
        return true;
    }

    /**
     * persist the profile to local storage, ie. session, database, memcache etc
     *
     * @return boolean
     */
    public function save()
    {
        // replace with application specific logic
        return true;
    }

    /**
     * take an array of profile data and return a fully compatible result set
     *
     * @param array $profile
     * @return \PhalconRest\Result\Result
     */
    public function getResult($profile = [])
    {

        $profile['userName'] = $this->userName;
        $profile['token'] = $this->token;

        $di = \Phalcon\Di::getDefault();
        // the final result generated by the entity object
        $result = $di->get('result', ['profile']);
        $result->outputMode = 'single';

        $id = (isset($profile['id'])) ? $profile['id'] : 1;
        $data = $di->get('data', [$id, 'profile', $profile]);
        // $data = New \PhalconRest\Result\Data($id, 'profile', $profile);
        $result->addData($data);
        return $result;
    }


    /**
     * export all public properties of the user profile in a simple array
     * occasionally useful when processing login requests
     *
     * @return array
     */
    public function toArray()
    {
        $fields = (array)$this;
        //this filters out any private or protected properties. object>array cast adds a null byte before those
        return array_filter($fields, function ($key) {
            return $key[0] !== "\0";
        }, ARRAY_FILTER_USE_KEY);
    }

}