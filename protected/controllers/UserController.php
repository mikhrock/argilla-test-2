<?php
/**
 * @author Alexey Tatarivov <tatarinov@shogo.ru>
 * @link https://github.com/shogodev/argilla/
 * @copyright Copyright &copy; 2003-2013 Shogo
 * @license http://argilla.ru/LICENSE
 * @package frontend.controllers
 */
class UserController extends FController
{
  /* TriggMine start */
  protected $TriggMine;
  
  public function init()
  {
    Yii::import('webroot.backend.protected.modules.triggmine.TriggmineModule');
    $this->TriggMine = new TriggmineModule;
  }
  /* TriggMine end */
  
  public function filters()
  {
    return array(
      'accessControl',
    );
  }

  public function accessRules()
  {
    return array(
      array(
        'deny',
        'actions' => array('data', 'orders', 'history', 'profile'),
        'users' => array('?'),
      ),
    );
  }

  public function actionLogin()
  {
    if( !Yii::app()->user->isGuest )
      $this->redirect($this->createUrl('user/profile'), true, 200);

    $this->breadcrumbs = array('Вход');

    $loginForm = $this->loginForm;
    $loginForm->ajaxValidation();
    $loginForm->loadData();

    if( $loginForm->validate() )
    {
      if( $loginForm->model->loginUser() )
      {
        
        /* TriggMine start */
        if ( $this->TriggMine->_TriggMineEnabled )
        {
          $dataCustomer = $this->TriggMine->getCustomerData( 'LoginEvent', $loginForm->model->attributes );
          $response = $this->TriggMine->client->SendEvent( $dataCustomer );

          if ( $this->TriggMine->debugMode )
          {
            Yii::log( 'login', CLogger::LEVEL_INFO, 'triggmine' );
            Yii::log( json_encode( $dataCustomer ), CLogger::LEVEL_INFO, 'triggmine' );
            Yii::log( CVarDumper::dumpAsString( $response ), CLogger::LEVEL_INFO, 'triggmine' );
          }
        }
        /* TriggMine end */
        
        $this->redirect(Yii::app()->user->returnUrl);
        Yii::app()->end();
      }
      else
        $loginForm->model->addError('Login_authError', 'Ошибка неверный логин/пароль!');
    }

    $this->render('login', array('loginForm' => $loginForm));
  }

  public function actionLogout()
  {
    /* TriggMine start */
    if ( $this->TriggMine->_TriggMineEnabled )
    {
      $dataCustomer = $this->TriggMine->getCustomerData( 'LogoutEvent' );
      $response = $this->TriggMine->client->SendEvent( $dataCustomer );
      
      if ( $this->TriggMine->debugMode )
      {
        Yii::log( 'logout', CLogger::LEVEL_INFO, 'triggmine' );
        Yii::log( json_encode( $dataCustomer ), CLogger::LEVEL_INFO, 'triggmine' );
        Yii::log( CVarDumper::dumpAsString( $response ), CLogger::LEVEL_INFO, 'triggmine' );
      }
    }
    /* TriggMine end */
    
    Yii::app()->user->logout();
    $this->redirect($this->createUrl('index/index'));
    Yii::app()->end();
  }

  public function actionRegistration()
  {
    if( Yii::app()->user->isGuest )
    {
      $this->breadcrumbs = array('Регистрация');

      $registrationForm = new FForm('UserRegistration', new UserRegistration());
      $registrationForm->loadFromSession = true;
      $registrationForm->clearAfterSubmit = true;
      $registrationForm->autocomplete = false;
      $registrationForm->ajaxSubmit = false;
      $registrationForm['extendedData']->model = new UserDataExtended();

      if( Yii::app()->request->isPostRequest )
        $registrationForm->model->email = CHtml::encode(Yii::app()->request->getParam('email', ''));

      $registrationForm->ajaxValidation();

      if( Yii::app()->request->isPostRequest && $registrationForm->save() )
      {
        $this->authenticateNewUser($registrationForm->model);

        Yii::app()->notification->send(
          $registrationForm->model,
          array(
            'userData' => $registrationForm['extendedData']->model,
            'password' => Yii::app()->request->getParam('UserRegistration')['password']
          ),
          $registrationForm->model->email
        );

        Yii::app()->notification->send(
          'UserRegistrationBackend',
          array(
            'model' => $registrationForm->model,
            'userData' => $registrationForm['extendedData']->model
          )
        );
        $this->redirect($this->createUrl('user/registrationSuccess'), true, 200);
      }

      $this->render('registration', array('registrationForm' => $registrationForm));
    }
    else
      $this->redirect($this->createUrl('user/profile'), true, 200);
  }

  public function actionFastRegistration()
  {
    if( Yii::app()->user->isGuest )
    {
      if( !Yii::app()->request->isAjaxRequest )
        throw new CHttpException('404', 'Странца не найдена');

      $password = Utils::generatePassword(9);

      $this->fastRegistrationForm->model->password = $password;

      if( !$this->fastRegistrationForm->process() )
      {
        echo CJSON::encode(array(
          'status' => 'ok',
          'message' => $this->fastRegistrationForm->getErrorMessage()
        ));
        Yii::app()->end();
      }

      if( $this->fastRegistrationForm->save() )
      {
        $this->authenticateNewUser($this->fastRegistrationForm->model);

        Yii::app()->notification->send('UserFastRegistration',
          array(
            'model' => $this->fastRegistrationForm->model,
            'password' => $password
          ),
          $this->fastRegistrationForm->model->email
        );

        Yii::app()->notification->send('UserFastRegistrationBackend', array('model' => $this->fastRegistrationForm->model));

        echo CJSON::encode(array(
          'status' => 'ok',
          'messageForm' => $this->textBlockRegister(
            'Успешная быстрая регистрация',
            'Регистрация успешно завершена'
          ),
          'hideElements' => array('fastRegInfo')
        ));
        Yii::app()->end();
      }
    }
  }

  public function actionRegistrationSuccess()
  {
    $this->breadcrumbs = array('Успешная регистрация');
    $this->render('registration_success', array('messageForm' => $this->textBlockRegister('Регистрация успешно завершена'),));
  }

  public function actionRestore()
  {
    $this->breadcrumbs = array('Восстановление пароля');

    $restoreForm = new FForm('UserRestore', new UserRestore());
    $restoreForm->validateOnChange = false;
    $restoreForm->ajaxValidation();

    if( Yii::app()->request->isAjaxRequest && $restoreForm->process() )
    {
      $record = $restoreForm->model->findByAttributes(
        array('email' => $restoreForm->getModel()->email)
      );
      $record->generateRestoreCode();
      $restoreForm->responseSuccess(Yii::app()->controller->textBlockRegister(
        'Email успешно отправлен',
        'Вам на E-mail отправлены дальнейшие инструкции'
      ));
    }
    else
      $this->render('restore', array('restoreForm' => $restoreForm));
  }

  public function actionRestoreConfirmed($code)
  {
    $this->breadcrumbs = array('Восстановление пароля');

    $record = UserRestore::model()->findByAttributes(array('restore_code' => $code));
    if( $record )
    {
      $record->generateNewPassword();
      $this->render('restore', array('restoreForm' => 'Новый пароль выслан на ваш E-mail.'));
    }
    else
      $this->redirect(array('user/restore'));
  }

  public function actionProfile()
  {
    $this->breadcrumbs = array('Профиль');

    $user = User::model()->findByPk(Yii::app()->user->getId());
    $defaultAddress = UserAddress::model()->defaultAddress()->find();
    $this->render('profile',
      array(
        'user' => $user,
        'defaultAddress' => $defaultAddress
      )
    );
  }

  public function actionData()
  {
    $this->breadcrumbs = array(
      'Профиль' => array('user/profile'),
      'Личные данные',
    );

    $userForm = new FForm('UserData', User::model()->findByPk(Yii::app()->user->getId()));
    $userForm['extendedData']->model = UserDataExtended::model()->findByPk(Yii::app()->user->getId());
    $userForm->ajaxValidation();

    if( Yii::app()->request->isAjaxRequest && $userForm->save() )
    {
      $userForm->responseSuccess(Yii::app()->controller->textBlockRegister(
        'Успешное изменение пользовательских данных',
        'Изменения сохранены'
      ));
    }

    $this->render('userData', array('userForm' => $userForm));
  }

  public function actionOrders()
  {
    $this->breadcrumbs = array('Текущие заказы');

    $criteria = new CDbCriteria();
    $criteria->addNotInCondition('status_id', array(OrderStatus::STATUS_CANCELED, OrderStatus::STATUS_DELIVERED));
    $criteria->compare('user_id', Yii::app()->user->getId());
    $criteria->order = 'date_create DESC';

    $orders = Order::model()->findAll($criteria);

    $this->render('history/orders', array(
      'orders' => $orders,
    ));
  }

  public function actionHistory()
  {
    $this->breadcrumbs = array('История заказов');

    $orders = array();
    $model = Order::model();

    $filterKeys = $model->getFilterKeys(Yii::app()->user->getId());
    if( !empty($filterKeys) )
      $orders = $model->getFilteredOrders(Yii::app()->user->getId(), !empty($_GET['filter']) ? $_GET['filter'] : $filterKeys[0]['id']);

    $this->render('history/history', array(
        'model' => $model,
        'orders' => $orders,
        'filterKeys' => $filterKeys)
    );
  }

  public function actionHistoryOne($id)
  {
    $order = Order::model()->findByPk($id);

    if( empty($order) )
      throw new CHttpException(404, 'Страница не найдена');

    $this->breadcrumbs = array('История заказов', 'Заказ №'.$order->id);

    $this->render('orderHistoryOne', array('order' => $order, 'backUrl' => $this->createUrl('user/history')));
  }

  public function actionPassword()
  {
    if( Yii::app()->user->isGuest )
      throw new CHttpException(404, 'Страница не найдена');

    $changePasswordForm = new FForm('ChangePasswordForm', UserChangePassword::model()->findByPk(Yii::app()->user->getId()));
    $changePasswordForm->ajaxValidation();

    if( Yii::app()->request->isAjaxRequest && $changePasswordForm->save() )
    {
      $changePasswordForm->responseSuccess(Yii::app()->controller->textBlockRegister(
        'Успешное изменение пароля',
        'Изменения сохранены'
      ));
    }

    $this->breadcrumbs = array('Изменение пароля');
    $this->render('change_password', array('changePasswordForm' => $changePasswordForm));
  }

  public function actionBonuse()
  {
    if( Yii::app()->user->isGuest )
      throw new CHttpException(404, 'Страница не найдена');

    $this->breadcrumbs = array('Бонусная система');

    $data = array(
      'confirmedBonuse' => BonuseUser::model()->confirmedBonuse(Yii::app()->user->id),
      'reservedBonuse' => BonuseUser::model()->reservedBonuse(Yii::app()->user->id),
    );

    $this->render('bonuse/bonuse', $data);
  }

  protected function authenticateNewUser(UserRegistration $model)
  {
    $loginModel = new Login();
    $loginModel->login = $model->email;
    $loginModel->password = $model->password;
    $loginModel->loginUser();
    
    /* TriggMine start */
    if ( $this->TriggMine->_TriggMineEnabled )
    {
      $dataCustomer = $this->TriggMine->getCustomerData( 'ProspectEvent', $model->attributes );
      $response = $this->TriggMine->client->SendEvent( $dataCustomer );
      
      if ( $this->TriggMine->debugMode )
      {
        Yii::log( 'registration', CLogger::LEVEL_INFO, 'triggmine' );
        Yii::log( json_encode( $dataCustomer ), CLogger::LEVEL_INFO, 'triggmine' );
        Yii::log( CVarDumper::dumpAsString( $response ), CLogger::LEVEL_INFO, 'triggmine' );
      }
    }
    /* TriggMine end */
  }
}