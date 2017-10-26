<?php
/**
 * @author Sergey Glagolev <glagolev@shogo.ru>
 * @link https://github.com/shogodev/argilla/
 * @copyright Copyright &copy; 2003-2013 Shogo
 * @license http://argilla.ru/LICENSE
 * @package backend.modules.product
 *
 * @method static BProduct model(string $class = __CLASS__)
 *
 * @property string $id
 * @property integer $position
 * @property string $url
 * @property string $name
 * @property string $articul
 * @property string $price
 * @property string $price_old
 * @property string $price_selection
 * @property string $notice
 * @property string $content
 *
 * @property integer $gift
 * @property integer $visible
 * @property integer $spec
 * @property integer $novelty
 * @property integer $vegan
 * @property integer $lenten
 * @property integer $unit
 * @property integer $label_price
 * @property integer $discount
 * @property integer $main
 * @property integer $dump
 * @property integer $archive
 * @property integer $xml
 * @property integer $pre_order

 * @property integer $section_id
 * @property integer $type_id
 *
 * @property BProductAssignment $assignment
 * @property BAssociation[] $associations
 * @property BProductIngredientAssignment[] $ingredientsAssignment
 */
class BProduct extends BActiveRecord implements IHasFrontendModel
{
  /* TriggMine start */
  protected $TriggMine;
  
  public function __construct()
  {
    Yii::import('webroot.protected.modules.triggmine.TriggmineModule');
    Yii::import('webroot.protected.modules.product.models.*');
    $this->TriggMine = new TriggmineModule( 'backend' );
  }
  
  public function triggmineGetProductSaveData()
  {
    Yii::import( 'webroot.protected.modules.triggmine.models.ProductExportEvent' );
		Yii::import( 'webroot.protected.modules.triggmine.models.ProductHistoryEvent' );
		
		$baseURL = Yii::app()->request->hostInfo;

	  $dataExport = new ProductHistoryEvent;
	  
	  $productItem = $this->attributes;
	  
	  $productSection = Yii::app()->db->createCommand()
	      ->select('s.name')
	      ->from('kalitniki_product_section AS s')
	      ->join('kalitniki_product_assignment AS a', 'a.product_id = ' . $productItem['id'])
	      ->where('s.id = a.section_id');
	  $productSection = $productSection->queryAll();
	  $productSection = $productSection[0];
	  
	  $productPrices = array();
	  if ( $this->discount )
	  {
	    $productPrice = array(
        'price_id'             => "",
        'price_value'          => Utils::calcDiscountPrice($productItem['price'], $this->discount),
        'price_priority'       => null,
        'price_active_from'    => "",
        'price_active_to'      => "",
        'price_customer_group' => "",
        'price_quantity'       => ""
      );
      
      $productPrices[] = $productPrice;
	  }
	  
	  $productCategories = array();
    $productCategories[] = $productSection['name'];
	    		
	  $product = new ProductExportEvent;
    $product->product_id               = $productItem['id'];
    $product->parent_id                = $productItem['parent'] ? $productItem['parent'] : "";
    $product->product_name             = isset( $productItem['name'] ) ? $productItem['name'] : "";
    $product->product_desc             = $productItem['notice'];
    $product->product_create_date      = $productItem['date_create'];
    $product->product_sku              = $productItem['articul'] ? $productItem['articul'] : "";
    $product->product_image            = $baseURL . '/' . $productItem['img'];
    $product->product_url              = $baseURL . '/' . $productItem['url'];
    $product->product_qty              = "";
    $product->product_default_price    = $productItem['price'];
    $product->product_prices           = $productPrices;
    $product->product_categories       = $productCategories;
    $product->product_relations        = "";
    $product->product_is_removed       = "";
    $product->product_is_active        = $productItem['visible'];
    $product->product_active_from      = "";
    $product->product_active_to        = "";
    $product->product_show_as_new_from = "";
    $product->product_show_as_new_to   = "";
                
    $dataExport->products[] = $product;
    
    return $dataExport;
  }
  /* TriggMine end */
  
  public function __get($name)
  {
    $fields = BProductAssignment::model()->getFields();

    if( isset($fields[$name]) )
    {
      $relation = str_replace('_id', '', $name);

      if( is_array($this->$relation) )
        $value = CHtml::listData($this->$relation, 'id', 'id');
      else if( isset($this->$relation->id) )
        $value = $this->$relation->id;
      else
        $value = null;
    }
    else
    {
      $value = parent::__get($name);
    }

    return $value;
  }

  public function __set($name, $value)
  {
    $fields = BProductAssignment::model()->getFields();

    if( isset($fields[$name]) )
      $this->$name = $value;
    else
      parent::__set($name, $value);
  }

  public function rules()
  {
    return array(
      array('url, name', 'required'),
      array('url, articul', 'unique'),
      array('parent, position, visible, spec, novelty, vegan, lenten, unit, main, dump, discount, archive, xml, label_price', 'numerical', 'integerOnly' => true),
      array('url, name, articul', 'length', 'max' => 255),
      array('notice, content, video, rating, pre_order, unit', 'safe'),
      array('url', 'SUriValidator'),
      array('price, price_old', 'numerical'),

      array('section_id, type_id', 'required'),
      array(implode(", ", array_keys(BProductAssignment::model()->getFields())), 'safe'),
    );
  }

  public function behaviors()
  {
    return array(
      'uploadBehavior' => array(
        'class' => 'UploadBehavior',
        'validAttributes' => 'product_img'
      ),
    );
  }

  public function relations()
  {
    return array(
      'assignment' => array(self::HAS_MANY, 'BProductAssignment', 'product_id'),
      'associations' => array(self::HAS_MANY, 'BAssociation', 'src_id', 'on' => 'src="bproduct"'),
      'products' => array(self::HAS_MANY, 'BProduct', 'dst_id', 'on' => 'dst="product"', 'through' => 'associations'),
      'section' => array(self::HAS_ONE, 'BProductSection', 'section_id', 'through' => 'assignment'),
      'type' => array(self::HAS_ONE, 'BProductType', 'type_id', 'through' => 'assignment'),
      'category' => array(self::HAS_ONE, 'BProductCategory', 'category_id', 'through' => 'assignment'),

      'ingredientsAssignment' => array(self::HAS_MANY, 'BProductIngredientAssignment', 'product_id'),
    );
  }

  public function beforeSave()
  {
    if( parent::beforeSave() )
    {
      if( empty($this->articul) )
        $this->articul = null;

      $this->price_selection = $this->getPriceSelection();

      /* TriggMine start */
      if ($this->TriggMine->_TriggMineEnabled)
      {
        $data = $this->triggmineGetProductSaveData();
        $response = $this->TriggMine->client->SendEvent( $data );
        
        if ( $this->TriggMine->debugMode )
      	{
          Yii::log(json_encode($data), CLogger::LEVEL_INFO, 'triggmine');
          Yii::log(CVarDumper::dumpAsString($response), CLogger::LEVEL_INFO, 'triggmine');
      	}
      }
      /* TriggMine end */

      return true;
    }

    return false;
  }

  public function getImageTypes()
  {
    return array(
      'main' => 'Основное',
    );
  }

  public function getSearchCriteria()
  {
    $criteria           = new CDbCriteria;
    $criteria->together = true;
    $criteria->distinct = true;

    $criteria->with = array('assignment' => [
      'select' => false,
    ]);

    $criteria->compare('assignment.section_id', '='.$this->section_id);
    $criteria->compare('assignment.type_id', '='.$this->type_id);

    $criteria->compare('position', $this->position);
    $criteria->compare('visible', $this->visible);
    $criteria->compare('xml', $this->xml);
    $criteria->compare('discount', $this->discount);
    $criteria->compare('spec', $this->spec);
    $criteria->compare('label_price', $this->label_price);
    $criteria->compare('novelty', $this->novelty);
    $criteria->compare('vegan', $this->vegan);
    $criteria->compare('lenten', $this->lenten);
    $criteria->compare('main', $this->main);

    $criteria->compare('name', $this->name, true);

    return $criteria;
  }

  public function attributeLabels()
  {
    return CMap::mergeArray(parent::attributeLabels(), array(
      'product_img' => 'Изображения',
      'BProduct' => 'Продукты',
      'content' => 'Состав',
      'ingredientIds' => 'Ингредиенты',
      'pre_order' => 'Предзаказ за (час.)',
      'label_price'=> 'Подпись: Цена за 1 шт.',
      'lenten'=> 'Постное блюдо',
      'unit'=> 'Единица измерения',
      'vegan'=> 'Вегетарианское блюдо',
      'xml'=> 'Выводить в XML',
    ));
  }

  public function getIngredientsDataProvider()
  {
    $productIngredients = array();
    $ingredients        = BProductIngredient::model()->findAllBySection($this->getSectionId());

    foreach($this->ingredientsAssignment as $ingredient)
    {
      $productIngredients[$ingredient->ingredient_id] = $ingredient;
    }

    foreach($ingredients as $i => $ingredient)
    {
      if( isset($productIngredients[$ingredient->id]) )
      {
        $ingredients[$i] = $productIngredients[$ingredient->id];
      }
    }

    return new CArrayDataProvider($ingredients);
  }

  /**
   * @return string
   */
  public function getFrontendModelName()
  {
    return 'Product';
  }

  public function getSectionId()
  {
    return $this->isNewRecord ? null : $this->section->id;
  }

  public function getCombinationsDataProvider()
  {
    $criteria = new CDbCriteria();
    $criteria->together = true;
    $criteria->with = array('param');
    $criteria->compare('product_id', $this->id);
    $criteria->addInCondition('param.parent', BProductParamName::model()->getBasketParameterIds());

    $combinations = array();

    if( isset($this->id) )
    {
      $parameters = BProductParam::model()->findAll($criteria);
      $combinations = BProductParamCombination::model()->getCombinations($parameters, $this->id);
    }

    return new CArrayDataProvider($combinations, array(
      'pagination' => false,
    ));
  }

  public function getPriceSelection()
  {
    $priceSelection = $this->price;

    $productParamCombination = BProductParamCombination::model()->findByAttributes(array('product_id' => $this->id, 'default_price' => 1));
    if( $productParamCombination && !Utils::isDecimalEmpty($productParamCombination->price) )
      $priceSelection = $productParamCombination->price;

    return $priceSelection;
  }
}