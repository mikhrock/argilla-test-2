<?php
/**
 * @author Alexey Tatarivov <tatarinov@shogo.ru>
 * @link https://github.com/shogodev/argilla/
 * @copyright Copyright &copy; 2003-2013 Shogo
 * @license http://argilla.ru/LICENSE
 * @package frontend.controllers
 */
class BasketController extends FController
{
  /* TriggMine start */
  protected $TriggMine;
  /* TriggMine end */
  
  public function init()
  {
    parent::init();
    
    /* TriggMine start */
    Yii::import('webroot.backend.protected.modules.triggmine.TriggmineModule');
    $this->TriggMine = new TriggmineModule;
    /* TriggMine end */
    
    $this->processBasketAction();
  }
  
  public function actionIndex()
  {
    $this->breadcrumbs = array('Корзина');
    
    //прослеживаем бадо ли изменение инградиентов в корзине для корректировки шаблона
    $openIngredients = false;
    $data = Yii::app()->request->getPost($this->basket->keyCollection);
    if( !empty($data['openIngredients']) && isset($data['id']) )
      $openIngredients = (int)$data['id'];
    
    $data = array(
      'openIngredients' => $openIngredients,
    );
    
    if( Yii::app()->request->isAjaxRequest && Yii::app()->request->getPost('completeBonuse') )
    {
      $dataBonuse = $this->completeBonuse();
      $this->renderPartial('/basket/basket', Arr::mergeAssoc($data, $dataBonuse));
    }
    else if( Yii::app()->request->isAjaxRequest && ($promoCodeRequest = Yii::app()->request->getPost('completePromoCode')) )
    {
      $dataPromoCode = $this->completePromoCode($promoCodeRequest);
      $this->renderPartial('/basket/basket', Arr::mergeAssoc($data, $dataPromoCode));
    }
    else
      $this->render('basket', $data);
  }
  
  public function actionPanel()
  {
    $this->renderPartial('basket_in_header');
    $this->renderPartial('/product_panel');
    $this->renderPartial('/popups');
  }
  
  /**
   * ECommerce
   */
  public function actionOneAj()
  {
    if( !Yii::app()->request->isAjaxRequest )
      throw new CHttpException(404, 'Страница не найдена');
    
    $data = Yii::app()->request->getPost('data');
    $element = Product::model()->findByPk(Arr::get($data, 'ecid'));
    
    $data = [];
    
    if( $element )
    {
      $element = new ECItem($element);
      
      $data = [
        'id' => $element->getId(),
        'name' => $element->getName(),
        'category' => $element->getCategiry(),
        'price' => $element->getPrice(),
        //'quantity' => $element->collectionAmount,
      ];
    }
    
    
    echo json_encode($data);
    Yii::app()->end();
  }
  
  public function actionCheckOut()
  {
    if( $this->basket->isEmpty() )
      Yii::app()->request->redirect($this->basket->url);
    
    $this->breadcrumbs = array('Корзина');
    
    $orderForm = $this->createOrderForm();
    $orderForm->ajaxValidation();
    $orderForm->process();
    
    $this->setOrderFormAttributes($orderForm);
    
    if( $orderForm->save() )
    {
      $this->saveUserAddress($orderForm);
      $this->savePayDetails($orderForm);
      
      Yii::app()->session['basket_formed_order'] = $orderForm->model->id;
      
      Yii::app()->notification->send('OrderBackend', array('basket' => $this->basket, 'model' => $orderForm->model, 'adminUrl' => $orderForm->model->getAdminUrl()));
      Yii::app()->notification->send($orderForm->model, array('basket' => $this->basket, 'form' => $orderForm), $orderForm->model->email);
      
      $this->userAutoRegistration($orderForm);
      
      if( !empty($orderForm->model->phone) )
      {
        $text = 'Ваш заказ № '.$orderForm->model->id.' принят! Ожидайте звонка оператора.';
        $sms = new SmsSender();
        $sms->sender(explode(',', $orderForm->model->phone), $text);
      }
      
      /* TriggMine start */
      // save the order cart to process it later on order success
      Yii::app()->session->add( 'orderCart', $this->basket->externalIndexStorage );
      /* TriggMine end */
      
       $this->basket->clear();
      $orderForm->clearSession();
      
      Yii::app()->session['orderSuccess'] = true;
      
      echo CJSON::encode(array(
        'status' => 'ok',
        'redirect' => $this->createAbsoluteUrl('basket/success'),
      ));
    }
    else
    {
      if( !Yii::app()->user->isGuest )
      {
        $orderForm->model->setAttributes(array(
          'name' => Yii::app()->user->data->name,
          'phone' => Yii::app()->user->data->phone,
          'email' => Yii::app()->user->email
        ));
      }
      
      $userAddresses = UserAddress::model()->findAll();
      
      $this->render('check_out', array(
        'form' => $orderForm,
        'metroZones' => MetroZone::model()->findAll(),
        'deliveryDate' => new DeliveryDate(),
        'userAddressIds' => $userAddresses ? CHtml::listData($userAddresses, 'id', function ($value)
        {
          return isset($value->metro) ? $value->metro->metroZone->id : 0;
        }) : array()
      ));
    }
  }
  
  public function actionSuccess()
  {
    if( $this->basket->isEmpty() && !Yii::app()->session->get('orderSuccess', false) )
      Yii::app()->request->redirect($this->basket->url);
    
    $this->breadcrumbs = array('Корзина');
    
    /** @var $order * */
    $order_id = Yii::app()->session->get('basket_formed_order');
    $order = Order::model()->findByPk($order_id);
    
    $platronUrl = null;
    if( !empty($order->payment) && (bool)$order->payment->system )
    {
      $platron = new PlatronSystem($order->id);
      $platron->userMode = true;
      $platronUrl = $platron->initPayment();
      Yii::app()->session->remove('basket_formed_order');
    }
    
    Yii::app()->session->remove('orderSuccess');
    
    $this->render('success', array('platronUrl' => $platronUrl, 'order' => $order));
    
    /* TriggMine start */
    if ($this->TriggMine->_TriggMineEnabled)
    {
      $data = $this->triggmineGetOrderData();
      $response = $this->TriggMine->client->SendEvent( $data );
      
      if ( $this->TriggMine->debugMode )
    	{
        Yii::log(json_encode($data), CLogger::LEVEL_INFO, 'triggmine');
        Yii::log(CVarDumper::dumpAsString($response), CLogger::LEVEL_INFO, 'triggmine');
    	}
    }
    /* Triggmine end */
  }
  
  public function actionDeliveryDate()
  {
    $date = Yii::app()->request->getPost('date');
    $timeBegin = Yii::app()->request->getPost('timeBegin');
    
    Yii::app()->session['deliveryDateTime'] = [
      'date' => $date,
      'time' => $timeBegin,
    ];
    
    if( Yii::app()->request->isAjaxRequest )
    {
      $deliveryDate = new DeliveryDate($date.' '.date('H:i'));
      
      if( !$deliveryDate->validate() )
        throw new CHttpException('500', 'Ошибка');
      
      if( $date && !$timeBegin )
      {
        echo CJSON::encode(array(
          'date' => $deliveryDate->beginDate,
          'beginTimeRange' => $deliveryDate->beginTimeRange,
          'endTimeRange' => $deliveryDate->endTimeRange
        ));
      }
      else if( $date && $timeBegin )
      {
        echo CJSON::encode(array(
          'endTimeRange' => $deliveryDate->getEndTimeRangeForSelectedBegin($timeBegin)
        ));
      }
    }
    else
      throw new CHttpException('404', 'Ошибка');
  }
  
  public function actionFastOrder()
  {
    $form = $this->fastOrderForm;
    
    $form->ajaxValidation();
    
    if( !$this->basket->isEmpty() && $form->save() )
    {
      Yii::app()->notification->send('OrderBackend', array('model' => $form->model));
      Yii::app()->notification->send($form->model, array(), $form->model->email);
      Yii::app()->user->setFlash('successFastOrder', $this->textBlockRegister('Успешный быстрый заказ', 'Заказ отправлен', null));
      
      echo CJSON::encode(array(
        'status' => 'ok',
        'reload' => '',
        'callbacks' => array('click_fast_submit' => 1)
      ));
      
      $this->basket->clear();
      
      Yii::app()->end();
    }
  }
  
  public function actionFavoriteToBasket()
  {
    foreach($this->favorite as $item)
    {
      $data = $item->toArray();
      unset($data['index']);
      
      $parameters = array();
      foreach($item->defaultParameters as $parameterId)
        $parameters[] = array('id' => $parameterId, 'type' => 'productParameter');
      
      $data['items'] = array('parameters' => $parameters);
      
      $this->basket->add($data);
    }
    
    $this->actionPanel();
  }
  
  public function completeBonuse()
  {
    if( !Yii::app()->request->isAjaxRequest )
      throw new CHttpException('404', 'Ошибка');
    
    $data = Yii::app()->request->getPost('completeBonuse');
    
    if( !$data || ($bonuse = Arr::get($data, 'bonuse')) === null )
      throw new CHttpException('404', 'Ошибка');
    
    $data = $this->basket->completeBonuse($bonuse);
    
    return $data;
  }
  
  public function completePromoCode($request)
  {
    $promoCode = trim(Arr::get($request, 'promoCode'));
    
    $errorMessage = null;
    
    $promoCodeWidget = new PromoCodeWidget();
    $errorMessage = $promoCodeWidget->completePromoCode($promoCode);
    
    $data = array(
      'errorMessage' => $errorMessage
    );
    
    return $data;
  }
  
  /**
   * @return FForm
   */
  protected function createOrderForm()
  {
    $orderForm = new FForm('Order', new Order());
    $orderForm->autocomplete = true;
    $orderForm->loadFromSession = true;
    
    $orderDelivery = new OrderDelivery();
    $orderDelivery->setDefaultState();
    $orderDelivery->changeState();
    $orderForm['orderDelivery']->model = $orderDelivery;
    
    $orderPayDetails = new OrderPayDetails();
    $orderPayDetails->setDefaultState();
    $orderPayDetails->changeState();
    $orderForm['orderPayDetails']->model = $orderPayDetails;
    
    $orderSelfDelivery = new OrderSelfDelivery();
    $orderForm['orderSelfDelivery']->model = $orderSelfDelivery;
    
    return $orderForm;
  }
  
  /**
   * @param $orderForm
   */
  protected function savePayDetails($orderForm)
  {
    /**
     * @var $payDetailsModel OrderPayDetails
     */
    $payDetailsModel = $orderForm['orderPayDetails']->model;
    
    if( $orderForm->model->payment_id == DirPayment::NON_CASH && $payDetailsModel->isNeedSaveNewPayDetails() )
    {
      $userPayDetails = new UserPaydetails();
      $userPayDetails->attributes = $payDetailsModel->attributes;
      $userPayDetails->setAttribute('name', $orderForm->model->name);
      $userPayDetails->setAttribute('phone', $orderForm->model->phone);
      $userPayDetails->setAttribute('email', $orderForm->model->email);
      
      $userPayDetails->save();
    }
  }
  
  /**
   * @param $orderForm
   */
  protected function saveUserAddress($orderForm)
  {
    /**
     * @var $deliveryModel OrderDelivery
     */
    $deliveryModel = $orderForm['orderDelivery']->model;
    
    if( $orderForm->model->delivery_id == DirDelivery::DELIVERY && $deliveryModel->isNeedSaveNewAddress() )
    {
      $metro = Metro::model()->findByPk($deliveryModel->metro_id);
      
      if( mb_strtolower($deliveryModel->city) != mb_strtolower($metro->city->name) )
        return;
      
      $userAddress = new UserAddress();
      $userAddress->attributes = $deliveryModel->attributes;
      $userAddress->setAttribute('name', $orderForm->model->name);
      $userAddress->setAttribute('phone', $orderForm->model->phone);
      $userAddress->setAttribute('city_id', $metro->city_id);
      
      $userAddress->save();
    }
  }
  
  /**
   * @param $orderForm
   */
  protected function setOrderFormAttributes($orderForm)
  {
    $deliveryModel = $orderForm['orderDelivery']->model;
    $selfDeliveryModel = $orderForm['orderSelfDelivery']->model;
    $payDetailsModel = $orderForm['orderPayDetails']->model;
    $model = $orderForm->model;
    
    if( $model->delivery_id == DirDelivery::SELF_DELIVERY )
    {
      $model->address = '';
      $model->date = $selfDeliveryModel->date;
      $model->time = $selfDeliveryModel->time;
    }
    
    if( $model->delivery_id == DirDelivery::DELIVERY )
    {
      if( !empty($deliveryModel->known_address) && $deliveryModel->known_address != 'new' )
      {
        $userAddress = UserAddress::model()->findByPk($deliveryModel->known_address);
        $model->city = $userAddress->city->name;
        $model->address = $userAddress->toString();
      }
      else
      {
        $model->city = $deliveryModel->city;
        $model->address = $deliveryModel->toString();
      }
      $model->address .= ', '.$model->name;
      
      if( !empty($model->phone) )
        $model->phone .= ', '.$model->phone;
      
      $model->date = $deliveryModel->date;
      $model->time = $deliveryModel->time;
    }
    
    if( $model->payment_id == DirPayment::NON_CASH )
    {
      if( !empty($payDetailsModel->known_pay_details) && $payDetailsModel->known_pay_details != 'new' )
      {
        $userPayDetails = UserPaydetails::model()->findByPk($payDetailsModel->known_pay_details);
        $_POST['OrderPayDetails'] = $userPayDetails->attributes;
      }
    }
  }
  
  protected function processBasketAction()
  {
    $request = Yii::app()->request;
    
    if( !$request->isAjaxRequest )
      return;
    
    $data = $request->getPost($this->basket->keyCollection);
    $action = $request->getPost('action');
    
    if( $data && $action )
    {
      switch($action)
      {
        case 'remove':
          $id = Arr::get($data, 'id');
          
          if( !$this->basket->getElementByIndex($id) )
            throw new CHttpException(500, 'Данный продукт уже удален. Обновите страницу.');
          
          $this->basket->remove($id);
          break;
        
        case 'changeAmount':
          if( !$this->basket->getElementByIndex($data['id']) )
            throw new CHttpException(500, 'Продукт не найден. Обновите страницу.');
          
          $amount = intval($data['amount']);
          $this->basket->change($data['id'], $amount > 0 ? $amount : 1);
          break;
        
        case 'add':
          $amount = intval(Arr::get($data, 'amount', 1));
          $data['amount'] = $amount > 0 ? $amount : 1;
          $this->basket->add($data);
          break;
        
        case 'changeParameter':
          $element = $this->basket->getElementByIndex($data['id']);
          
          if( !$element )
            throw new CHttpException(500, 'Продукт не найден. Обновите страницу.');
          
          $elementData = $element->toArray();
          
          
          $this->basket->change($data['id'], null, $data['items']);
          break;
      }
      
      /* TriggMine start */
      if ( $this->TriggMine->_TriggMineEnabled )
      {
        $dataCart = $this->triggmineGetCartData();
        $response = $this->TriggMine->client->SendEvent( $dataCart );
        
        if ( $this->TriggMine->debugMode )
    		{
          Yii::log( json_encode( $dataCart ), CLogger::LEVEL_INFO, 'triggmine' );
          Yii::log( CVarDumper::dumpAsString( $response ), CLogger::LEVEL_INFO, 'triggmine' );
    		}
      }
      /* TriggMine end */
    }
  }
  
  /* TriggMine start */
  protected function triggmineGetCartData()
  {
    //Yii::log('triggmine cart yo', CLogger::LEVEL_INFO, 'triggmine');
    Yii::import('webroot.backend.protected.modules.triggmine.models.ProductEvent');
    Yii::import('webroot.backend.protected.modules.triggmine.models.CartEvent');
    
    $baseURL = Yii::app()->request->hostInfo;
    $dataCustomer = $this->TriggMine->getCustomerData();
    $qtyTotal = 0;
    $priceTotal = 0;
    
    $data = new CartEvent;
    $data->customer    = $dataCustomer;
    $data->order_id    = null;
    $data->price_total = $priceTotal;
    $data->qty_total   = $qtyTotal;
    $data->products    = array();
    
    $cartContents = $this->basket->externalIndexStorage;
    //Yii::log( CVarDumper::dumpAsString( $cartContents ), CLogger::LEVEL_INFO, 'triggmine' );
    foreach ( $cartContents as $cartItem )
    {
      $attributes = $cartItem->attributes;

      if ( !isset( $attributes['variant_id'] ) ) // exclude product variants
      {
        if ( isset( $attributes['ingredient_id'] ) )
        {
          // ingredients
          if ( $cartItem->collectionAmount > 0 )
          {
            $productCategories = array();

            $itemData = new ProductEvent;
            $itemData->product_id         = (string)$attributes['id'];
            $itemData->product_name       = $cartItem->getName();
            $itemData->product_desc       = "";
            $itemData->product_sku        = "";
            $itemData->product_image      = "";
            $itemData->product_url        = "";
            $itemData->product_qty        = $cartItem->collectionAmount;
            $itemData->product_price      = $attributes['price']; 
            $itemData->product_total_val  = $itemData->product_price * $itemData->product_qty;
            $itemData->product_categories = $productCategories;
            
            $data->products[] = $itemData;
            $data->price_total += $itemData->product_total_val;
            $data->qty_total += $itemData->product_qty;
          }
        }
        else
        {
          // regular products
          $productCategories = array();
          $productCategories[] = $cartItem->section->attributes['name'];
          
          $itemData = new ProductEvent;
          $itemData->product_id         = (string)$attributes['id'];
          $itemData->product_name       = $attributes['name'];
          $itemData->product_desc       = $attributes['notice'];
          $itemData->product_sku        = $attributes['articul'];
          $itemData->product_image      = $baseURL . $cartItem->getImage()->pre;
          $itemData->product_url        = $baseURL . $attributes['url'];
          $itemData->product_qty        = $cartItem->collectionAmount;
          $itemData->product_price      = $cartItem->getPrice(); 
          $itemData->product_total_val  = $itemData->product_price * $itemData->product_qty;
          $itemData->product_categories = $productCategories;
          
          $data->products[] = $itemData;
          $data->price_total += $itemData->product_total_val;
          $data->qty_total += $itemData->product_qty;
        }
      }
    }
    
    return $data;
  }
  
  protected function triggmineGetOrderData()
  {
    Yii::import('webroot.backend.protected.modules.triggmine.models.ProductEvent');
    Yii::import('webroot.backend.protected.modules.triggmine.models.OrderEvent');
    
    $baseURL = Yii::app()->request->hostInfo;
    
    /** @var $order * */
    $order_id = Yii::app()->session->get( 'basket_formed_order' );
    $order = Order::model()->findByPk( $order_id );
    
    $dataCustomer = $this->TriggMine->getCustomerData();
    
    $dataCustomer->customer_id           = $order->attributes['user_id'];
    $dataCustomer->customer_first_name   = $order->attributes['name'];
    $dataCustomer->customer_email        = $order->attributes['email'];

    $qtyTotal = 0;
    
    $data = new OrderEvent;
    $data->customer     = $dataCustomer;
    $data->order_id     = $order_id;
    $data->date_created = $order->attributes['date_create'];
    $data->status       = $this->TriggMine->getOrderStatus( $order->attributes['status_id'] );
    $data->price_total  = $order->attributes['sum'];
    $data->qty_total    = $qtyTotal;
    $data->products     = array();
    
    $cartContents = Yii::app()->session->get( 'orderCart' );
    
    foreach ( $cartContents as $cartItem )
    {
      $attributes = $cartItem->attributes;

      if ( !isset( $attributes['variant_id'] ) ) // exclude product variants
      {
        if ( isset( $attributes['ingredient_id'] ) )
        {
          // ingredients
          if ( $cartItem->collectionAmount > 0 )
          {
            $productCategories = array();

            $itemData = new ProductEvent;
            $itemData->product_id         = (string)$attributes['id'];
            $itemData->product_name       = $cartItem->getName();
            $itemData->product_desc       = "";
            $itemData->product_sku        = "";
            $itemData->product_image      = "";
            $itemData->product_url        = "";
            $itemData->product_qty        = $cartItem->collectionAmount;
            $itemData->product_price      = $cartItem->getPrice(); 
            $itemData->product_total_val  = $itemData->product_price * $itemData->product_qty;
            $itemData->product_categories = $productCategories;
            
            $data->products[] = $itemData;
            $data->qty_total += $itemData->product_qty;
          }
        }
        else
        {
          // regular products
          $productCategories = array();
          $productCategories[] = $cartItem->section->attributes['name'];
          
          $itemData = new ProductEvent;
          $itemData->product_id         = (string)$attributes['id'];
          $itemData->product_name       = $attributes['name'];
          $itemData->product_desc       = $attributes['notice'];
          $itemData->product_sku        = $attributes['articul'];
          $itemData->product_image      = $baseURL . $cartItem->getImage()->pre;
          $itemData->product_url        = $baseURL . $attributes['url'];
          $itemData->product_qty        = $cartItem->collectionAmount;
          $itemData->product_price      = $attributes['price_selection']; 
          $itemData->product_total_val  = $itemData->product_price * $itemData->product_qty;
          $itemData->product_categories = $productCategories;
          
          $data->products[] = $itemData;
          $data->qty_total += $itemData->product_qty;
        }
      }
    }
    
    Yii::app()->session->remove( 'orderCart' );
    
    return $data;
  }
  /* TriggMine end */
  
  protected function userAutoRegistration($orderForm)
  {
    if( !empty($orderForm->model->dayBirthday) && !empty($orderForm->model->monthBirthday) && !empty($orderForm->model->yearBirthday) )
      $birthday = date('Y-m-d', strtotime("{$orderForm->model->yearBirthday}-{$orderForm->model->monthBirthday}-{$orderForm->model->dayBirthday}"));
    
    if( Yii::app()->user->isGuest && !empty($orderForm->model->email) )
    {
      if( $user = User::model()->findByAttributes(array('email' => trim($orderForm->model->email))) )
      {
        $orderForm->model->user_id = $user->id;
        $orderForm->model->save(false);
        return;
      }
      
      $password = Utils::generatePassword(9);
      
      $form = new FForm('UserFastRegistration', new UserRegistration('orderRegistration'));
      $form['extendedData']->model = new UserDataExtended('orderRegistration');
      
      $form->model->attributes = array(
        'email' => $orderForm->model->email,
        'password' => $password
      );
      
      $form['extendedData']->model->attributes = array(
        'name' => $orderForm->model->name,
        'phone' => $orderForm->model->phone,
        'birthday' => !empty($birthday) ? $birthday : null,
      );
      
      if( $form->save() )
      {
        $data = array(
          'model' => $form->model,
          'userData' => $form['extendedData']->model,
          'password' => $password
        );
        
        $orderForm->model->user_id = $form->model->id;
        $orderForm->model->save(false);
        
        Yii::app()->notification->send('UserAutoRegistration', $data, $orderForm->model->email);
        Yii::app()->notification->send('UserAutoRegistrationBackend', $data);
      }
    }
    else if( !Yii::app()->user->isGuest && $orderForm->model->requiredBirthday() )
    {
      $user = Yii::app()->user;
      $user->data->birthday = $birthday;
      $user->data->save(false);
    }
  }
}