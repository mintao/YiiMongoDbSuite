<?php

/**
 * EMongoDocument
 *
 * @property MongoDB $db read-only
 */
abstract class EMongoDocument extends EMongoEmbeddedDocument
{
	private $_new=false;							// whether this instance is new or not
	private $_criteria=null;						// query criteria (used by finder only)
	private static $_collections=array();			// MongoCollection object
	private static $_models=array();
	private static $_indexes = array();				// Hold collection indexes array

	protected $ensureIndexes=true;					// Whatever to ensure indexes

	/**
	 * EMongoDB component static instance
	 * @var EMongoDB $_emongoDb;
	 */
	protected static $_emongoDb;

	/**
	 * MongoDB special field, every document has to have this
	 *
	 * @var mixed $_id
	 */
	public $_id;

	/**
	 * Add scopes functionality
	 * @see CComponent::__call()
	 */
	public function __call($name, $parameters)
	{
		$scopes=$this->scopes();
		if(isset($scopes[$name]))
		{
			$this->getDbCriteria()->mergeWith($scopes[$name]);
			return $this;
		}

		return parent::__call($name,$parameters);
	}

	/**
	 * Constructor {@see setScenario()}
	 *
	 * @param string $scenario
	 */
	public function __construct($scenario='insert')
	{
		if($scenario==null) // internally used by populateRecord() and model()
			return;

		$this->setScenario($scenario);
		$this->setIsNewRecord(true);

		$this->init();

		$this->attachBehaviors($this->behaviors());
		$this->afterConstruct();

		$this->initEmbeddedDocuments();
	}

	/**
	 * Return the primary key field for this collection, defaults to '_id'
	 * @return string|array field name, or array of fields for composite primary key
	 */
	public function primaryKey()
	{
		return '_id';
	}


	public function getPrimaryKey()
	{
		$pk = $this->primaryKey();
		if(is_string($pk))
			return $this->{$pk};
		else
		{
			$return = array();
			foreach($pk as $pkFiled)
				$return[] = $this->{$pkFiled};

			return $return;
		}
	}

	/**
	 * Get EMongoDB component instance
	 * By default it is mongodb application component
	 *
	 * @return EMongoDB
	 */
	public function getMongoDBComponent()
	{
		if(self::$_emongoDb===null)
			self::$_emongoDb = Yii::app()->getComponent('mongodb');

		return self::$_emongoDb;
	}

	/**
	 * Set EMongoDB component instance
	 * @param EMongoDB $component
	 */
	public function setMongoDBComponent(EMongoDB $component)
	{
		self::$_emongoDb = $component;
	}

	/**
	 * Get raw MongoDB instance
	 * @return MongoDB
	 */
	public function getDb()
	{
		return $this->getMongoDBComponent()->getDbInstance();
	}

	/**
	 * This method must return collection name for use with this model
	 * this must be implemented in child classes
	 *
	 * this is read-only defined only at class define
	 * if you whant to set different colection during run-time
	 * use {@see setCollection()}
	 *
	 * @return string collection name
	 */
	abstract public function getCollectionName();

	/**
	 * Returns current MongoCollection object
	 * By default this method use {@see getCollectionName()}
	 * @return MongoCollection
	 */
	public function getCollection()
	{
		if(!isset(self::$_collections[$this->getCollectionName()]))
			self::$_collections[$this->getCollectionName()] = $this->getDb()->selectCollection($this->getCollectionName());

		return self::$_collections[$this->getCollectionName()];
	}

	/**
	 * Set current MongoCollection object
	 * @param MongoCollection $collection
	 */
	public function setCollection(MongoCollection $collection)
	{
		self::$_collections[$this->getCollectionName()] = $collection;
	}

	/**
	 * Returns if the current record is new.
	 * @return boolean whether the record is new and should be inserted when calling {@link save}.
	 * This property is automatically set in constructor and {@link populateRecord}.
	 * Defaults to false, but it will be set to true if the instance is created using
	 * the new operator.
	 */
	public function getIsNewRecord()
	{
		return $this->_new;
	}

	/**
	 * Sets if the record is new.
	 * @param boolean $value whether the record is new and should be inserted when calling {@link save}.
	 * @see getIsNewRecord
	 */
	public function setIsNewRecord($value)
	{
		$this->_new=$value;
	}

	/**
	 * Returns the mongo criteria associated with this model.
	 * @param boolean $createIfNull whether to create a criteria instance if it does not exist. Defaults to true.
	 * @return EMongoCriteria the query criteria that is associated with this model.
	 * This criteria is mainly used by {@link scopes named scope} feature to accumulate
	 * different criteria specifications.
	 */
	public function getDbCriteria($createIfNull=true)
	{
		if($this->_criteria===null)
			if(($c = $this->defaultScope()) !== array() || $createIfNull)
				$this->_criteria = new EMongoCriteria($c);
		return $this->_criteria;
	}

	/**
	 * Set girrent object, this will override proevious criteria
	 *
	 * @param EMongoCriteria $criteria
	 */
	public function setDbCriteria($criteria)
	{
		if(is_array($criteria))
			$this->_criteria = new EMongoCriteria($criteria);
		else if($criteria instanceof EMongoCriteria)
			$this->_criteria = $criteria;
		else
			$this->_criteria = new EMongoCriteria();
	}

	/**
	 * This function check indexes and applyes them to the collection if needed
	 * see CModel::init()
	 *
	 * @see EMongoEmbeddedDocument::init()
	 */
	public function init()
	{
		parent::init();

		if($this->ensureIndexes && empty(self::$_indexes[$this->getCollectionName()]))
		{
			$indexInfo = $this->getCollection()->getIndexInfo();
			array_shift($indexInfo); // strip out default _id index

			$indexes = array();
			foreach($indexInfo as $index)
			{
				$indexes[$index['name']] = array(
					'key'=>$index['key'],
					'unique'=>$index['unique'],
				);
			}
			self::$_indexes[$this->getCollectionName()] = $indexes;

			$this->ensureIndexes();
		}
	}

	/**
	 * This function may return array of indexes for this collection
	 * array sytnatx is:
	 * return array(
	 * 	'index_name'=>array('key'=>array('fieldName1'=>EMongoCriteria::SORT_ASC, 'fieldName2'=>EMongoCriteria::SORT_DESC),
	 * 	'index2_name'=>array('key'=>array('fieldName3'=>EMongoCriteria::SORT_ASC, 'unique'=>true),
	 * );
	 * @return array list of indexes for this collection
	 */
	public function indexes()
	{
		return array();
	}

	private function ensureIndexes()
	{
		$indexNames = array_keys(self::$_indexes[$this->getCollectionName()]);
		foreach($this->indexes() as $name=>$index)
		{
			if(!in_array($name, $indexNames))
			{
				$this->getCollection()->ensureIndex(
					$index['key'],
					array('unique'=>isset($index['unique']) ? $index['unique'] : false, 'name'=>$name)
				);
				self::$_indexes[$this->getCollectionName()][$name] = $index;
			}
		}
	}

	/**
	 * Returns the declaration of named scopes.
	 * A named scope represents a query criteria that can be chained together with
	 * other named scopes and applied to a query. This method should be overridden
	 * by child classes to declare named scopes for the particular document classes.
	 * For example, the following code declares two named scopes: 'recently' and
	 * 'published'.
	 * <pre>
	 * return array(
	 *     'published'=>array(
	 *           'conditions'=>array(
	 *                 'status'=>array('==', 1),
	 *           ),
	 *     ),
	 *     'recently'=>array(
	 *           'sort'=>array('create_time'=>EMongoCriteria::SORT_DESC),
	 *           'limit'=>5,
	 *     ),
	 * );
	 * </pre>
	 * If the above scopes are declared in a 'Post' model, we can perform the following
	 * queries:
	 * <pre>
	 * $posts=Post::model()->published()->findAll();
	 * $posts=Post::model()->published()->recently()->findAll();
	 * $posts=Post::model()->published()->published()->recently()->find();
	 * </pre>
	 *
	 * @return array the scope definition. The array keys are scope names; the array
	 * values are the corresponding scope definitions. Each scope definition is represented
	 * as an array whose keys must be properties of {@link EMongoCriteria}.
	 */
	public function scopes()
	{
		return array();
	}

	/**
	 * Returns the default named scope that should be implicitly applied to all queries for this model.
	 * Note, default scope only applies to SELECT queries. It is ignored for INSERT, UPDATE and DELETE queries.
	 * The default implementation simply returns an empty array. You may override this method
	 * if the model needs to be queried with some default criteria (e.g. only active records should be returned).
	 * @return array the mongo criteria. This will be used as the parameter to the constructor
	 * of {@link EMongoCriteria}.
	 */
	public function defaultScope()
	{
		return array();
	}

	/**
	 * Resets all scopes and criterias applied including default scope.
	 *
	 * @return EMongoDocument
	 */
	public function resetScope()
	{
		$this->_criteria = new EMongoCriteria();
		return $this;
	}

	/**
	 * Applies the query scopes to the given criteria.
	 * This method merges {@link dbCriteria} with the given criteria parameter.
	 * It then resets {@link dbCriteria} to be null.
	 * @param EMongoCriteria $criteria the query criteria. This parameter may be modified by merging {@link dbCriteria}.
	 */
	public function applyScopes(&$criteria)
	{
		if($criteria === null) $criteria = new EMongoCriteria();
		if(($c=$this->getDbCriteria(false))!==null)
		{
			$c->mergeWith($criteria);
			$criteria=$c;
			$this->_criteria=null;
		}
	}

	/**
	 * Saves the current record.
	 *
	 * The record is inserted as a row into the database table if its {@link isNewRecord}
	 * property is true (usually the case when the record is created using the 'new'
	 * operator). Otherwise, it will be used to update the corresponding row in the table
	 * (usually the case if the record is obtained using one of those 'find' methods.)
	 *
	 * Validation will be performed before saving the record. If the validation fails,
	 * the record will not be saved. You can call {@link getErrors()} to retrieve the
	 * validation errors.
	 *
	 * If the record is saved via insertion, its {@link isNewRecord} property will be
	 * set false, and its {@link scenario} property will be set to be 'update'.
	 * And if its primary key is auto-incremental and is not set before insertion,
	 * the primary key will be populated with the automatically generated key value.
	 *
	 * @param boolean $runValidation whether to perform validation before saving the record.
	 * If the validation fails, the record will not be saved to database.
	 * @param array $attributes list of attributes that need to be saved. Defaults to null,
	 * meaning all attributes that are loaded from DB will be saved.
	 * @return boolean whether the saving succeeds
	 */
	public function save($runValidation=true,$attributes=null)
	{
		if(!$runValidation || $this->validate($attributes))
			return $this->getIsNewRecord() ? $this->insert($attributes) : $this->update($attributes);
		else
			return false;
	}

	/**
	 * Inserts a row into the table based on this active record attributes.
	 * If the table's primary key is auto-incremental and is null before insertion,
	 * it will be populated with the actual value after insertion.
	 * Note, validation is not performed in this method. You may call {@link validate} to perform the validation.
	 * After the record is inserted to DB successfully, its {@link isNewRecord} property will be set false,
	 * and its {@link scenario} property will be set to be 'update'.
	 * @param array $attributes list of attributes that need to be saved. Defaults to null,
	 * meaning all attributes that are loaded from DB will be saved.
	 * @return boolean whether the attributes are valid and the record is inserted successfully.
	 * @throws CException if the record is not new
	 */
	public function insert(array $attributes=null)
	{
		if(!$this->getIsNewRecord())
			throw new CDbException(Yii::t('yii','The EMongoDocument cannot be inserted to database because it is not new.'));
		if($this->beforeSave())
		{
			Yii::trace(get_class($this).'.insert()','ext.MongoDb.EMongoDocument');
			$rawData = $this->toArray();
			// free the '_id' container if empty, mongo will not populate it if exists
			if(empty($rawData['_id']))
				unset($rawData['_id']);
			// filter attributes if set in param
			if($attributes!==null)
			{
				foreach($rawData as $key=>$value)
				{
					if(!in_array($key, $attributes))
						unset($rawData[$key]);
				}
			}
			$result = $this->getCollection()->insert($rawData, array(
				'fsync'=>$this->getMongoDBComponent()->fsyncFlag
			));

			if($result !== false && !empty($rawData['_id'])) // strict comparsion driver may return empty array
			{
				$this->_id = $rawData['_id'];
				$this->setIsNewRecord(false);
				$this->setScenario('update');
				$this->afterSave();

				return true;
			}

			throw new CException(Yii::t('yii', 'Can\t save document to disk, or try to save empty document!'));
		}
		return false;
	}

	/**
	 * Updates the row represented by this active record.
	 * All loaded attributes will be saved to the database.
	 * Note, validation is not performed in this method. You may call {@link validate} to perform the validation.
	 * @param array $attributes list of attributes that need to be saved. Defaults to null,
	 * meaning all attributes that are loaded from DB will be saved.
	 * @return boolean whether the update is successful
	 * @throws CException if the record is new
	 */
	public function update(array $attributes=null)
	{
		if($this->getIsNewRecord())
			throw new CDbException(Yii::t('yii','The EMongoDocument cannot be updated because it is new.'));
		if($this->beforeSave())
		{
			Yii::trace(get_class($this).'.update()','ext.MongoDb.EMongoDocument');
			$rawData = $this->toArray();
			// filter attributes if set in param
			if($attributes!==null)
			{
				foreach($rawData as $key=>$value)
				{
					if(!in_array($key, $attributes))
						unset($rawData[$key]);
				}
			}
			$result = $this->getCollection()->save($rawData, array(
				'fsync'=>$this->getMongoDBComponent()->fsyncFlag
			));

			if($result !== false) // strict comparsion driver may return empty array
			{
				$this->afterSave();

				return true;
			}

			throw new CException(Yii::t('yii', 'Can\t save document to disk, or try to save empty document!'));
		}
	}

	/**
	 * Deletes the row corresponding to this EMongoDocument.
	 * @return boolean whether the deletion is successful.
	 * @throws CException if the record is new
	 */
	public function delete()
	{
		if(!$this->getIsNewRecord())
		{
			Yii::trace(get_class($this).'.delete()','ext.MongoDb.EMongoDocument');
			if($this->beforeDelete())
			{
				$result = $this->deleteByPk($this->getPrimaryKey());

				if($result)
				{
					$this->afterDelete();
					$this->_id=null;
					$this->setIsNewRecord(true);
					return true;
				}
				else
					return false;
			}
			else
				return false;
		}
		else
			throw new CDbException(Yii::t('yii','The EMongoDocument cannot be deleted because it is new.'));
	}

	/**
	 * Repopulates this active record with the latest data.
	 * @return boolean whether the row still exists in the database. If true, the latest data will be populated to this active record.
	 */
	public function refresh()
	{
		Yii::trace(get_class($this).'.refresh()','ext.MongoDb.EMongoDocument');
		if(!$this->getIsNewRecord() && $this->getCollection()->count(array('_id'=>$this->_id))==1)
		{
			$this->setAttributes($this->getCollection()->find(array('_id'=>$this->_id)), false);
			return true;
		}
		else
			return false;
	}

	/**
	 * Finds a single EMongoDocument with the specified condition.
	 * @param array|EMongoCriteria $condition query criteria.
	 *
	 * If an array, it is treated as the initial values for constructing a {@link EMongoCriteria} object;
	 * Otherwise, it should be an instance of {@link EMongoCriteria}.
	 *
	 * @return EMongoDocument the record found. Null if no record is found.
	 */
	public function find($criteria=null)
	{
		Yii::trace(get_class($this).'.find()','ext.MongoDb.EMongoDocument');

		if($this->beforeFind())
		{
			$this->applyScopes($criteria);

			$doc = $this->getCollection()->findOne($criteria->getConditions());

			return $this->populateRecord($doc);
		}
		return null;
	}

	/**
	 * Finds all documents satisfying the specified condition.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param array|EMongoCriteria $condition query criteria.
	 * @return array list of documents satisfying the specified condition. An empty array is returned if none is found.
	 */
	public function findAll($criteria=null)
	{
		Yii::trace(get_class($this).'.findAll()','ext.MongoDb.EMongoDocument');
		if($this->beforeFind())
		{
			$this->applyScopes($criteria);

			$cursor = $this->getCollection()->find($criteria->getConditions());

			if($criteria->getSort() !== null)
				$cursor->sort($criteria->getSort());
			if($criteria->getLimit() !== null)
				$cursor->limit($criteria->getLimit());
			if($criteria->getOffset() !== null)
				$cursor->skip($criteria->getOffset());

			return $this->populateRecords($cursor);
		}
		return array();
	}

	/**
	 * Finds document with the specified primary key.
	 * In MongoDB world every document has '_id' unique field, so with this method that
	 * field is in use as PK!
	 * See {@link find()} for detailed explanation about $condition.
	 * @param mixed $pk primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
	 * @param array|EMongoCriteria $condition query criteria.
	 * @return the document found. An null is returned if none is found.
	 */
	public function findByPk($pk, $criteria=null)
	{
		Yii::trace(get_class($this).'.findByPk()','ext.MongoDb.EMongoDocument');
		$criteria = new EMongoCriteria($criteria);
		$criteria->mergeWith($this->createPkCriteria($pk));

		return $this->find($criteria);
	}

	/**
	 * Finds all documents with the specified primary keys.
	 * In MongoDB world every document has '_id' unique field, so with this method that
	 * field is in use as PK by default.
	 * See {@link find()} for detailed explanation about $condition.
	 * @param mixed $pk primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
	 * @param array|EMongoCriteria $condition query criteria.
	 * @return the document found. An null is returned if none is found.
	 */
	public function findAllByPk($pk, $criteria=null)
	{
		Yii::trace(get_class($this).'.findAllByPk()','ext.MongoDb.EMongoDocument');
		$criteria = new EMongoCriteria($criteria);
		$criteria->mergeWith($this->createPkCriteria($pk, true));

		return $this->findAll($criteria);
	}

	/**
	 * Finds document with the specified attributes.
	 *
	 * See {@link find()} for detailed explanation about $condition.
	 * @param mixed $pk primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
	 * @param array|EMongoCriteria $condition query criteria.
	 * @return the document found. An null is returned if none is found.
	 */
	public function findByAttributes(array $attributes)
	{
		$criteria = new EMongoCriteria();
		foreach($attributes as $name=>$value)
		{
			$criteria->$name('==', $value);
		}

		return $this->find($criteria);
	}

	/**
	 * Finds all documents with the specified attributes.
	 *
	 * See {@link find()} for detailed explanation about $condition.
	 * @param mixed $pk primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
	 * @param array|EMongoCriteria $condition query criteria.
	 * @return the document found. An null is returned if none is found.
	 */
	public function findAllByAttributes(array $attributes)
	{
		$criteria = new EMongoCriteria();
		foreach($attributes as $name=>$value)
		{
			$criteria->$name('==', $value);
		}

		return $this->findAll($criteria);
	}

	/**
	 * Counts all documents satisfying the specified condition.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param array|EMongoCriteria $condition query criteria.
	 * @return integer Count of all documents satisfying the specified condition.
	 */
	public function count($criteria=null)
	{
		Yii::trace(get_class($this).'.count()','ext.MongoDb.EMongoDocument');

		$this->applyScopes($criteria);

		return $this->getCollection()->count($criteria->getConditions());
	}

	/**
	 * Counts all documents satisfying the specified condition.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param array|EMongoCriteria $condition query criteria.
	 * @return integer Count of all documents satisfying the specified condition.
	 */
	public function countByAttributes(array $attributes)
	{
		Yii::trace(get_class($this).'.countByAttributes()','ext.MongoDb.EMongoDocument');

		$criteria = new EMongoCriteria;
		foreach($attributes as $name=>$value)
			$criteria->$name = $value;

		$this->applyScopes($criteria);

		return $this->getCollection()->count($crit->getConditions());
	}

	/**
	 * Deletes document with the specified primary key.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param mixed $pk primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
	 * @param array|EMongoCriteria $condition query criteria.
	 */
	public function deleteByPk($pk, $criteria=null)
	{
		Yii::trace(get_class($this).'.deleteByPk()','ext.MongoDb.EMongoDocument');
		if($this->beforeDelete())
		{
			$this->applyScopes($criteria);
			$criteria->mergeWith($this->createPkCriteria($pk));

			$result = $this->getCollection()->remove($criteria->getConditions(), array(
				'justOne'=>true,
				'fsync'=>$this->getMongoDBComponent()->fsyncFlag
			));
			$this->afterDelete();
			return $result;
		}
		return false;
	}

	/**
	 * Deletes documents with the specified primary keys.
	 * See {@link find()} for detailed explanation about $condition and $params.
	 * @param mixed $pk primary key value(s). Use array for multiple primary keys. For composite key, each key value must be an array (column name=>column value).
	 * @param array|EMongoCriteria $condition query criteria.
	 */
	public function deleteAll($criteria=null)
	{
		Yii::trace(get_class($this).'.deleteByPk()','ext.MongoDb.EMongoDocument');
		$this->applyScopes($criteria);

		return $this->getCollection()->remove($criteria->getConditions(), array(
			'justOne'=>false,
			'fsync'=>$this->getMongoDBComponent()->fsyncFlag
		));
	}

	/**
	 * This event is raised before the record is saved.
	 * By setting {@link CModelEvent::isValid} to be false, the normal {@link save()} process will be stopped.
	 * @param CModelEvent $event the event parameter
	 */
	public function onBeforeSave($event)
	{
		$this->raiseEvent('onBeforeSave',$event);
	}

	/**
	 * This event is raised after the record is saved.
	 * @param CEvent $event the event parameter
	 */
	public function onAfterSave($event)
	{
		$this->raiseEvent('onAfterSave',$event);
	}

	/**
	 * This event is raised before the record is deleted.
	 * By setting {@link CModelEvent::isValid} to be false, the normal {@link delete()} process will be stopped.
	 * @param CModelEvent $event the event parameter
	 */
	public function onBeforeDelete($event)
	{
		$this->raiseEvent('onBeforeDelete',$event);
	}

	/**
	 * This event is raised after the record is deleted.
	 * @param CEvent $event the event parameter
	 */
	public function onAfterDelete($event)
	{
		$this->raiseEvent('onAfterDelete',$event);
	}

	/**
	 * This event is raised before finder performs a find call.
	 * In this event, the {@link CModelEvent::criteria} property contains the query criteria
	 * passed as parameters to those find methods. If you want to access
	 * the query criteria specified in scopes, please use {@link getDbCriteria()}.
	 * You can modify either criteria to customize them based on needs.
	 * @param CModelEvent $event the event parameter
	 * @see beforeFind
	 */
	public function onBeforeFind($event)
	{
		$this->raiseEvent('onBeforeFind',$event);
	}

	/**
	 * This event is raised after the record is instantiated by a find method.
	 * @param CEvent $event the event parameter
	 */
	public function onAfterFind($event)
	{
		$this->raiseEvent('onAfterFind',$event);
	}

	/**
	 * This method is invoked before saving a record (after validation, if any).
	 * The default implementation raises the {@link onBeforeSave} event.
	 * You may override this method to do any preparation work for record saving.
	 * Use {@link isNewRecord} to determine whether the saving is
	 * for inserting or updating record.
	 * Make sure you call the parent implementation so that the event is raised properly.
	 * @return boolean whether the saving should be executed. Defaults to true.
	 */
	protected function beforeSave()
	{
		if($this->hasEventHandler('onBeforeSave'))
		{
			$event=new CModelEvent($this);
			$this->onBeforeSave($event);
			return $event->isValid;
		}
		else
			return true;
	}

	/**
	 * This method is invoked after saving a record successfully.
	 * The default implementation raises the {@link onAfterSave} event.
	 * You may override this method to do postprocessing after record saving.
	 * Make sure you call the parent implementation so that the event is raised properly.
	 */
	protected function afterSave()
	{
		if($this->hasEventHandler('onAfterSave'))
			$this->onAfterSave(new CEvent($this));
	}

	/**
	 * This method is invoked before deleting a record.
	 * The default implementation raises the {@link onBeforeDelete} event.
	 * You may override this method to do any preparation work for record deletion.
	 * Make sure you call the parent implementation so that the event is raised properly.
	 * @return boolean whether the record should be deleted. Defaults to true.
	 */
	protected function beforeDelete()
	{
		if($this->hasEventHandler('onBeforeDelete'))
		{
			$event=new CModelEvent($this);
			$this->onBeforeDelete($event);
			return $event->isValid;
		}
		else
			return true;
	}

	/**
	 * This method is invoked after deleting a record.
	 * The default implementation raises the {@link onAfterDelete} event.
	 * You may override this method to do postprocessing after the record is deleted.
	 * Make sure you call the parent implementation so that the event is raised properly.
	 */
	protected function afterDelete()
	{
		if($this->hasEventHandler('onAfterDelete'))
			$this->onAfterDelete(new CEvent($this));
	}

	/**
	 * This method is invoked before an AR finder executes a find call.
	 * The find calls include {@link find}, {@link findAll}, {@link findByPk},
	 * {@link findAllByPk}, {@link findByAttributes} and {@link findAllByAttributes}.
	 * The default implementation raises the {@link onBeforeFind} event.
	 * If you override this method, make sure you call the parent implementation
	 * so that the event is raised properly.
	 *
	 * Starting from version 1.1.5, this method may be called with a hidden {@link CDbCriteria}
	 * parameter which represents the current query criteria as passed to a find method of AR.
	 *
	 */
	protected function beforeFind()
	{
		if($this->hasEventHandler('onBeforeFind'))
		{
			$event=new CModelEvent($this);
			$this->onBeforeFind($event);
			return $event->isValid;
		}
		else
			return true;
	}

	/**
	 * This method is invoked after each record is instantiated by a find method.
	 * The default implementation raises the {@link onAfterFind} event.
	 * You may override this method to do postprocessing after each newly found record is instantiated.
	 * Make sure you call the parent implementation so that the event is raised properly.
	 */
	protected function afterFind()
	{
		if($this->hasEventHandler('onAfterFind'))
			$this->onAfterFind(new CEvent($this));
	}

	/**
	 * Creates an document instance.
	 * This method is called by {@link populateRecord} and {@link populateRecords}.
	 * You may override this method if the instance being created
	 * depends the attributes that are to be populated to the record.
	 * @param array $attributes list of attribute values for the active records.
	 * @return EMongoDocument the document
	 */
	protected function instantiate($attributes)
	{
		$class=get_class($this);
		$model=new $class(null);
		$model->initEmbeddedDocuments();
		$model->setAttributes($attributes, false);
		return $model;
	}

	/**
	 * Creates an EMongoDocument with the given attributes.
	 * This method is internally used by the find methods.
	 * @param array $attributes attribute values (column name=>column value)
	 * @param boolean $callAfterFind whether to call {@link afterFind} after the record is populated.
	 * This parameter is added in version 1.0.3.
	 * @return EMongoDocument the newly created document. The class of the object is the same as the model class.
	 * Null is returned if the input data is false.
	 */
	public function populateRecord($document, $callAfterFind=true)
	{
		if($document!==null)
		{
			$model=$this->instantiate($document);
			$model->setScenario('update');
			$model->init();

			$model->attachBehaviors($model->behaviors());

			if($callAfterFind)
				$model->afterFind();
			return $model;
		}
		else
			return null;
	}

	/**
	 * Creates a list of documents based on the input data.
	 * This method is internally used by the find methods.
	 * @param array $data list of attribute values for the active records.
	 * @param boolean $callAfterFind whether to call {@link afterFind} after each record is populated.
	 * This parameter is added in version 1.0.3.
	 * @param string $index the name of the attribute whose value will be used as indexes of the query result array.
	 * If null, it means the array will be indexed by zero-based integers.
	 * @return array list of active records.
	 */
	public function populateRecords($data, $callAfterFind=true, $index=null)
	{
		$records=array();
		foreach($data as $attributes)
		{
			if(($record=$this->populateRecord($attributes,$callAfterFind))!==null)
			{
				if($index===null)
					$records[]=$record;
				else
					$records[$record->$index]=$record;
			}
		}
		return $records;
	}

	/**
	 * Magic search method, provides basic search functionality.
	 *
	 * Returns EMongoDocument object ($this) with criteria set to
	 * rexexp: /$attributeValue/i
	 * used for Data provider search functionality
	 * @param boolean $caseSensitive whathever do a case-sensitive search, default to false
	 * @return EMongoDocument
	 */
	public function search($caseSensitive = false)
	{
		$criteria = $this->getDbCriteria();

		foreach($this->getSafeAttributeNames() as $attribute)
		{
			if($this->$attribute !== null && $this->$attribute !== '')
			{
				if(is_array($this->$attribute) || is_object($this->$attribute))
					$criteria->$attribute = $this->$attribute;
				else if(preg_match('/^(?:\s*(<>|<=|>=|<|>|=|!=|==))?(.*)$/',$this->$attribute,$matches))
				{
					$op = $matches[1];
					$value = $matches[2];

					if($op === '=') $op = '==';

					if($op !== '')
						call_user_func(array($criteria, $attribute), $op, is_numeric($value) ? floatval($value) : $value);
					else
						$criteria->$attribute = new MongoRegex($caseSensitive ? '/'.$this->$attribute.'/' : '/'.$this->$attribute.'/i');
				}
			}
		}

		$this->setDbCriteria($criteria);

		return $this;
	}

	/**
	 * Returns the static model of the specified EMongoDocument class.
	 * The model returned is a static instance of the EMongoDocument class.
	 * It is provided for invoking class-level methods (something similar to static class methods.)
	 *
	 * EVERY derived EMongoDocument class must override this method as follows,
	 * <pre>
	 * public static function model($className=__CLASS__)
	 * {
	 *     return parent::model($className);
	 * }
	 * </pre>
	 *
	 * @param string $className EMongoDocument class name.
	 * @return EMongoDocument EMongoDocument model instance.
	 */
	public static function model($className=__CLASS__)
	{
		if(isset(self::$_models[$className]))
			return self::$_models[$className];
		else
		{
			$model=self::$_models[$className]=new $className(null);
			$model->attachBehaviors($model->behaviors());
			return $model;
		}
	}

	private function createPkCriteria($pk, $multiple=false)
	{
		$pkField = $this->primaryKey();
		$criteria = new EMongoCriteria();

		if(is_string($pkField))
		{
			if(!$multiple)
				$criteria->{$pkField} = $pk;
			else
				$criteria->{$pkField}('in', $pk);
		}
		else if(is_array($pkField))
		{
			if(!$multiple)
				for($i=0; $i<count($pkField); $i++)
					$criteria->{$pkField[$i]} = $pk[$i];
			else
				throw new EMongoException(Yii::t('yii', 'Cannot create PK criteria for multiple composite key\'s (not implemented yet)'));
		}

		return $criteria;
	}
}