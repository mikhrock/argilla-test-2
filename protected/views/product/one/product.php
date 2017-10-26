<?php
/**
 * @var ProductController $this
 * @var Product $model
 * @var array $_data_
 */
?>

<?php $this->renderPartial('/breadcrumbs');?>

<div class="product-block nofloat m30">

  <?php $this->renderPartial('one/_image', $_data_);?>

  <div class="info-block">
    <?php $this->renderPartial('one/_basket_block', $_data_);?>
  </div>
</div>

<?php $this->renderPartial('one/_social_likes', $_data_);?>

<?php $this->renderPartial('one/_also_buy', $_data_);?>

<?php $this->renderPartial('one/_responses', $_data_);?>

<?php
//ECommerce
Yii::app()->ECommerce->renderScriptCatalogBefore();
Yii::app()->ECommerce->renderScriptCardProduct($model);
Yii::app()->ECommerce->renderScriptToBasket($model, 'to-basket');
?>

<?php
/* TriggMine start */
  Yii::import('webroot.backend.protected.modules.triggmine.TriggmineModule');
  
  $TriggMine = new TriggmineModule;
  
  if ( $TriggMine->_TriggMineEnabled )
  {
        Yii::import('webroot.backend.protected.modules.triggmine.models.ProductEvent');
				Yii::import('webroot.backend.protected.modules.triggmine.models.NavigationEvent');
				
				$botDetected = $TriggMine->isBot();
			  
			  if ( $botDetected )
			  {
			   // $userAgent = Yii::app()->request->userAgent;
			   // Yii::log('bot detected: ' . $userAgent, CLogger::LEVEL_INFO, 'triggmine');
			  }
			  else
			  {
          $baseURL = Yii::app()->request->hostInfo;
          
          $dataCustomer = $TriggMine->getCustomerData();
          
          $productCategories = array();
          $productCategories[] = $model->section->attributes['name'];
          
  				$dataProduct = new ProductEvent;
          $dataProduct->product_id         = $model->id;
          $dataProduct->product_name       = $model->name;
          $dataProduct->product_desc       = $model->notice;
          $dataProduct->product_sku        = $model->articul;
          $dataProduct->product_image      = $baseURL . $model->getImage()->pre;
          $dataProduct->product_url        = $baseURL . $model->url;
          $dataProduct->product_qty        = 1;
          $dataProduct->product_price      = $model->getPrice();
          $dataProduct->product_total_val  = $model->getPrice();
          $dataProduct->product_categories = $productCategories;
          
          //Yii::log(CVarDumper::dumpAsString($model->section->attributes), CLogger::LEVEL_INFO, 'triggmine');
          $dataNavigation = new NavigationEvent;
          $dataNavigation->user_agent = Yii::app()->request->userAgent;
          $dataNavigation->customer   = $dataCustomer;
          $dataNavigation->products[] = $dataProduct;
  				
  				$response = $TriggMine->client->SendEvent( $dataNavigation );

  				if ( $TriggMine->debugMode )
  				{
  				  Yii::log( json_encode( $dataNavigation ), CLogger::LEVEL_INFO, 'triggmine' );
  				  Yii::log( CVarDumper::dumpAsString( $response ), CLogger::LEVEL_INFO, 'triggmine' );
  				}
  				
  				if ( !$TriggMine->installed )
  			  {
  			    // TriggMine diagnostic
  			    // $TriggMine->sendDiagnosticEvent();
  			    
  			    // TriggMine export
            //$TriggMine->exportProducts();
            // $TriggMine->exportCustomers();
            // $TriggMine->exportOrders();
  			  }
			  }
  }
/* TriggMine end */
?>
