<?php
namespace Codeception\Module;

use Codeception\Lib\Interfaces\DataMapper;
use Codeception\Module as CodeceptionModule;
use Codeception\Exception\ModuleConfigException;
use Codeception\Lib\Interfaces\DoctrineProvider;
use Codeception\TestInterface;
use Doctrine\Common\Persistence\ObjectManager;
use Codeception\Util\Stub;

/**
 * Allows integration and testing for projects with DoctrineMongoOdm ODM.
 * DoctrineMongoOdm uses DocumentManager to perform all database operations.
 *
 * 
 * You should specify a callback function to receive entity manager:
 *
 * ```
 * modules:
 *     enabled:
 *         - DoctrineMongoOdm:
 *             connection_callback: ['MyDb', 'createDocumentManager']
 *
 * ```
 *
 * This will use static method of `MyDb::createDocumentManager()` to establish DocumentManager.
 *
 * By default module will wrap everything into transaction for each test and rollback it afterwards. By doing this
 * tests won't write anything to database, and so will run much faster and will be isolate dfrom each other.
 * This behavior can be changed by specifying `cleanup: false` in config.
 *
 * ## Status
 *
 * * Maintainer: **hlogeon**
 * * Stability: **unstable**
 * * Contact: hlogeon1@gmail.com
 *
 * ## Config
 *
 * * connection_callback: - callable that will return an instance of DocumentManager. This is a must
 *
 *  ### Example (`functional.suite.yml`)
 *
 *      modules:
 *         enabled: [DoctrineMongoOdm]
 *         config:
 *            DoctrineMongoOdm:
 *               connection_callback: ['MyDb', 'createDocumentManager']
 *
 * ## Public Properties
 *
 * * `dm` - Document Manager
 */

class DoctrineMongoOdm extends CodeceptionModule implements DataMapper
{

    protected $config = [
        'cleanup' => true,
        'connection_callback' => false,
        'depends' => null
    ];

    protected $dependencyMessage = <<<EOF
Provide connection_callback function to establish database connection and get Document Manager:

modules:
    enabled:
        - DoctrineMongoOdm:
            connection_callback: [My\ConnectionClass, getDocumentManager]
EOF;

    /**
     * @var Doctrine\Common\Persistence\ObjectManager
     */
    public $dm = null;

    public function _beforeSuite($settings = [])
    {
        $this->retrieveDocumentManager();
    }

    public function _before(TestInterface $test)
    {
        $this->retrieveDocumentManager();
    }

    protected function retrieveDocumentManager()
    {
        
        if (is_callable($this->config['connection_callback'])) {
            $this->dm = call_user_func($this->config['connection_callback']);
        }
        if (!$this->dm) {
            throw new ModuleConfigException(
                __CLASS__,
                "DocumentManager can't be obtained.\n \n"
                . "Please specify `connection_callback` config option\n"
                . "with callable which will return instance of DocumentManager"
            );
        }


        if (!($this->dm instanceof ObjectManager)) {
            throw new ModuleConfigException(
                __CLASS__,
                "Connection object is not an instance of \\Doctrine\\Common\\Persistence\\ObjectManager.\n"
                . "Use `connection_callback` to specify one"
            );
        }

        $this->dm->getConnection()->connect();
    }

    public function _after(TestInterface $test)
    {
        if (!$this->dm instanceof ObjectManager) {
            return;
        }
        $this->dm->clear();
        $this->dm->getConnection()->close();
    }


    /**
     * Performs $em->flush();
     */
    public function flushToDatabase()
    {
        $this->dm->flush();
    }


    /**
     * Adds entity to repository and flushes. You can redefine it's properties with the second parameter.
     *
     * Example:
     *
     * ``` php
     * <?php
     * $I->persistEntity(new \Entity\User, array('name' => 'Miles'));
     * $I->persistEntity($user, array('name' => 'Miles'));
     * ```
     *
     * @param $obj
     * @param array $values
     */
    public function persistEntity($obj, $values = [])
    {
        if ($values) {
            $reflectedObj = new \ReflectionClass($obj);
            foreach ($values as $key => $val) {
                $property = $reflectedObj->getProperty($key);
                $property->setAccessible(true);
                $property->setValue($obj, $val);
            }
        }

        $this->dm->persist($obj);
        $this->dm->flush();
    }

    /**
     * Persists record into repository.
     * This method crates an entity, and sets its properties directly (via reflection).
     * Setters of entity won't be executed, but you can create almost any entity and save it to database.
     * Returns id using `getId` of newly created entity.
     *
     * ```php
     * $I->haveInRepository('Entity\User', array('name' => 'hlogeon'));
     * ```
     */
    public function haveInRepository($entity, array $data)
    {
        $reflectedEntity = new \ReflectionClass($entity);
        $entityObject = $reflectedEntity->newInstance();
        foreach ($reflectedEntity->getProperties() as $property) {
            /** @var $property \ReflectionProperty */
            if (!isset($data[$property->name])) {
                continue;
            }
            $property->setAccessible(true);
            $property->setValue($entityObject, $data[$property->name]);
        }
        $this->dm->persist($entityObject);
        $this->dm->flush();

        if (method_exists($entityObject, 'getId')) {
            $id = $entityObject->getId();
            $this->debug("$entity entity created with id:$id");
            return $id;
        }
    }

    /**
     * Flushes changes to database executes a query defined by array.
     * It builds query based on array of parameters.
     * You can use entity associations to build complex queries.
     *
     * Example:
     *
     * ``` php
     * <?php
     * $I->seeInRepository('User', array('name' => 'hlogeon'));
     * $I->seeInRepository('User', array('name' => 'hlogeon', 'Company' => array('name' => 'Codegyre')));
     * $I->seeInRepository('Client', array('User' => array('Company' => array('name' => 'Codegyre')));
     * ?>
     * ```
     *
     * Fails if record for given criteria can\'t be found,
     *
     * @param $entity
     * @param array $params
     */
    public function seeInRepository($entity, $params = [])
    {
        $res = $this->proceedSeeInRepository($entity, $params);
        $this->assert($res);
    }

    /**
     * Flushes changes to database and performs ->findOneBy() call for current repository.
     *
     * @param $entity
     * @param array $params
     */
    public function dontSeeInRepository($entity, $params = [])
    {
        $res = $this->proceedSeeInRepository($entity, $params);
        $this->assertNot($res);
    }

    protected function proceedSeeInRepository($entity, $params = [])
    {
        // we need to store to database...
        $this->dm->flush();
        $data = $this->dm->getClassMetadata($entity);
        $qb = $this->dm->getRepository($entity)->createQueryBuilder('s');
        $this->buildAssociationQuery($qb, $entity, 's', $params);
        $this->debug($qb->getDQL());
        $res = $qb->getQuery()->getArrayResult();

        return ['True', (count($res) > 0), "$entity with " . json_encode($params)];
    }

    /**
     * Selects field value from repository.
     * It builds query based on array of parameters.
     * You can use entity associations to build complex queries.
     *
     * Example:
     *
     * ``` php
     * <?php
     * $email = $I->grabFromRepository('User', 'email', array('name' => 'hlogeon'));
     * ?>
     * ```
     *
     * @version 1.1
     * @param $entity
     * @param $field
     * @param array $params
     * @return array
     */
    public function grabFromRepository($entity, $field, $params = [])
    {
        // we need to store to database...
        $this->dm->flush();
        $data = $this->dm->getClassMetadata($entity);
        $qb = $this->dm->getRepository($entity)->createQueryBuilder('s');
        $qb->select('s.' . $field);
        $this->buildAssociationQuery($qb, $entity, 's', $params);
        $this->debug($qb->getDQL());
        return $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * It's Fuckin Recursive!
     *
     * @param $qb
     * @param $assoc
     * @param $alias
     * @param $params
     */
    protected function buildAssociationQuery($qb, $assoc, $alias, $params)
    {
        $data = $this->dm->getClassMetadata($assoc);
        foreach ($params as $key => $val) {
            if (isset($data->associationMappings)) {
                if ($map = array_key_exists($key, $data->associationMappings)) {
                    if (is_array($val)) {
                        $qb->innerJoin("$alias.$key", $key);
                        foreach ($val as $column => $v) {
                            if (is_array($v)) {
                                $this->buildAssociationQuery($qb, $map['targetEntity'], $column, $v);
                                continue;
                            }
                            $paramname = $key . '__' . $column;
                            $qb->andWhere("$key.$column = :$paramname");
                            $qb->setParameter($paramname, $v);
                        }
                        continue;
                    }
                }
            }
            if ($val === null) {
                $qb->andWhere("s.$key IS NULL");
            } else {
                $paramname = str_replace(".", "", "s_$key");
                $qb->andWhere("s.$key = :$paramname");
                $qb->setParameter($paramname, $val);
            }
        }
    }

    public function _getDocumentManager()
    {
        return $this->dm;
    }
}
