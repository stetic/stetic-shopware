<?php

/**
 * Stetic Analytics Shopware Plugin
 *
 * @category    Stetic
 * @package     Stetic_Analytics
 * @copyright   Copyright (c) 2014 Stetic (https://www.stetic.com/)
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

class Shopware_Plugins_Frontend_Stetic_Bootstrap extends Shopware_Components_Plugin_Bootstrap
{    
    /**
     * Handles PostDispatch events
     *
     * @param Enlight_Event_EventArgs $args
     * @return void
     */
    public function onPostDispatch(Enlight_Event_EventArgs $args)
    {
        $request = $args->getSubject()->Request();
        $response = $args->getSubject()->Response();
        $view = $args->getSubject()->View();

        if (!$request->isDispatched() 
            || $response->isException() 
            || $request->getModuleName() != 'frontend' 
            || !$view->hasTemplate() 
        ) { 
            return; 
        }

        $config = Shopware()->Plugins()->Frontend()->Stetic()->Config();
        $view->SteticConfig = $config;

        $view->addTemplateDir($this->Path() . 'Views/');
        $view->extendsTemplate('frontend/plugins/stetic/tracker.tpl');

        $this->Application()->Loader()->registerNamespace( 'Shopware\Stetic', $this->Path() . 'Components/');
        $Stetic = new Shopware\Stetic\Stetic($request, $view, $config);

        $Stetic->registerPostEvents();
        $Stetic->checkAccountViews();

        if($this->isAjax() || $response->isRedirect())
        {
            return;
        }
        $view->steticIdentify = $Stetic->identify();
        $view->steticEvents = $Stetic->getRegisteredEventsHtml();

        $view->steticController = $request->getControllerName();
        $view->steticData = $Stetic->getViewData();

        if(!isset(Shopware()->Session()->steticTest) || !is_array(Shopware()->Session()->steticTest))
        {
            Shopware()->Session()->steticTest = array();
        }

        Shopware()->Session()->steticTest[] = "Hallo " . time();

    }

    /**
     * Handles PreDispatch events
     *
     * @param Enlight_Event_EventArgs $args
     * @return void
     */
    public function onPreDispatch(Enlight_Event_EventArgs $args)
    {
        $request = $args->getSubject()->Request();
        $response = $args->getSubject()->Response();
        $view = $args->getSubject()->View();

        if ( !$request->isDispatched() || $response->isException() || $request->getModuleName() != 'frontend' )
        { 
            return; 
        }

        $controller = $request->getControllerName();
        $action = $request->getActionName();
        $config = Shopware()->Plugins()->Frontend()->Stetic()->Config();
        
        $this->Application()->Loader()->registerNamespace( 'Shopware\Stetic', $this->Path() . 'Components/');
        $Stetic = new Shopware\Stetic\Stetic($request, $view, $config);

        if( $controller == 'checkout' && $request->getParam('action') == 'deleteArticle' )
        {
            $Stetic->registerBasketDelete();
        }
        elseif( $request->getParam('controller') == 'account' && $request->getParam('action') == 'ajax_login' && $config->loginTracking)
        {
            $email = $request->getParam('email');
            if(!empty($email))
            {
                $Stetic->registerEvent('login', array("username" => $request->getParam('email')));
            }
        }
        elseif( $request->getParam('controller') == 'account' && $request->getParam('action') == 'ajax_logout' && $config->loginTracking )
        {
            $Stetic->registerEvent('logout');
        }
        elseif( $request->getParam('controller') == 'note' && $request->getParam('action') == 'delete' && $request->getParam('sDelete') )
        {
            $Stetic->registerWishlistDelete();
        }

    }

    /**
     * onRegister Hook
     *
     * @param Enlight_Event_EventArgs $args
     * @return void
     */
    public function onRegister(Enlight_Hook_HookArgs $args)
    {
        $error = false;
        $subject = $args->getSubject();
        $request = $subject->Request();
        $view = $subject->View();
        $post = Shopware()->Modules()->Admin()->sSYSTEM->_POST;

        if(isset($post['allowidentify']) && $post['allowidentify'] == '1')
        {
            Shopware()->Session()->steticUserNewAllowIdentify = 1;
        }
    }

    public function getCapabilities()
    {
        return array(
            'install' => true,
            'enable' => true,
            'disable' => true,
            'update' => false
        );
    }

    public function getLabel()
    {
        return 'Stetic';
    }

    public function getVersion()
    {
        return '1.0.0';
    }

    public function getInfo()
    {
        return array(
            'label' => $this->getLabel(),
            'version' => $this->getVersion(),
            'copyright' => 'Copyright (c) 2014, stetic',
            'autor' => 'Stetic',
            'revision' => '1',
            'link' => 'https://www.stetic.com'
        );
    }

    public function install()
    {
        $this->createConfiguration();
        $this->createAttributes();
        $this->createSnippets();
        
        $this->subscribeEvent(
            'Enlight_Controller_Action_PostDispatch',
            'onPostDispatch'
        );
        
        $this->subscribeEvent(
            'Enlight_Controller_Action_PreDispatch',
            'onPreDispatch'
        );
        
        $event = $this->createHook(
            'Shopware_Controllers_Frontend_Register',
            'savePersonalAction',
            'onRegister',
            Enlight_Hook_HookHandler::TypeAfter,
            0
        );
    
        $this->subscribeHook($event);

        return true;
    }

    /**
     * Creates configuration form elements.
     * Called on install.
     *
     * @return void
     */
    public function createConfiguration()
    {
        $form = $this->Form();
        $form->setElement('text', 'site_token',
            array(
            'label' => 'Site Token',
            'description' => 'Ihr Site Token von stetic.com',
            'required' => true
            )
        );

        $form->setElement('boolean', 'identifyLoggedin',
            array('label' => 'Eingeloggte Benutzer identifizieren', 'value' => true)
        );

        $form->setElement('boolean', 'userMustAllowidentify',
            array(
                'label' => 'Einwilligung zur Identifizierung bei Registrierung', 
                'description' => 'Wenn Sie diese Option aktivieren, wird dem Benutzer bei Registierung eine Checkbox angezeigt, über die er der Nutzung personenbezogener Daten zur Webanalyse zustimmen kann. Der Text kann über einen Textbaustein in der Registrierung angepasst werden.',
                'value' => false)
        );

        $form->setElement('boolean', 'cartTracking',
            array('label' => 'Warenkorb Tracking', 'value' => true)
        );

        $form->setElement('boolean', 'orderTracking',
            array(
                'label' => 'Order Tracking', 
                'description' => 'Erforderlich für E-Commerce Analytics.',
                'value' => true
            )
        );

        $form->setElement('boolean', 'wishlistTracking',
            array(
                'label' => 'Wishlist Tracking', 
                'value' => true
            )
        );


        $form->setElement('boolean', 'productReviewTracking',
            array(
                'label' => 'Product Review Tracking', 
                'value' => true
            )
        );

        $form->setElement('boolean', 'productCompareTracking',
            array(
                'label' => 'Product Compare Tracking', 
                'value' => true
            )
        );

        $form->setElement('boolean', 'sendfriendTracking',
            array(
                'label' => 'Tell a friend Tracking', 
                'value' => true
            )
        );

        $form->setElement('boolean', 'searchTracking',
            array(
                'label' => 'Search Tracking', 
                'value' => true
            )
        );

        $form->setElement('boolean', 'contactFormTracking',
            array(
                'label' => 'Contact form Tracking', 
                'value' => true
            )
        );

        $form->setElement('boolean', 'newsletterTracking',
            array(
                'label' => 'Newsletter Tracking', 
                'value' => true
            )
        );

        $form->setElement('boolean', 'accountCreateTracking',
            array(
                'label' => 'Account Register Tracking', 
                'value' => true
            )
        );

        $form->setElement('boolean', 'loginTracking',
            array(
                'label' => 'Login Tracking', 
                'value' => true
            )
        );

        $form->setElement('boolean', 'forgotPasswordTracking',
            array(
                'label' => 'Forgot Password Tracking', 
                'value' => true
            )
        );

        $shopRepository = Shopware()->Models()->getRepository('\Shopware\Models\Shop\Locale');
 
        $translations = array
        (
            'en_GB' => array(
                'site_token' => array("label" => "Site Token", "description" => "Your Site Token from stetic.com"),
                'identifyLoggedin' => array("label" => "Identify logged in users", "description" => "Required for Ecommerce Analytics."),
                'userMustAllowidentify' => array("label" => "Agreement of identifying on registration", "description" => "If you activate this option, a checkbox appears in the user registration, on which the user can agree to the use of personal data for Web Analytics. The text can be adjusted via snippets."),
            ),
        );

        foreach($translations as $locale => $snippets)
        {
            $localeModel = $shopRepository->findOneBy(array(
                'locale' => $locale
            ));

            if($localeModel === null)
            {
                continue;
            }

            foreach($snippets as $element => $snippet)
            {
                $elementModel = $form->getElement($element);

                if($elementModel === null)
                {
                    continue;
                }

                $translationModel = new \Shopware\Models\Config\ElementTranslation();
                $translationModel->setLabel($snippet['label']);
                $translationModel->setDescription($snippet['description']);
                $translationModel->setLocale($localeModel);

                $elementModel->addTranslation($translationModel);

            }
        }

    }

    /**
     * Creates needed attributes in database
     * Called on install.
     *
     * @return void
     */
    public function createAttributes()
    {
        try {
            
            $metaDataCache  = Shopware()->Models()->getConfiguration()->getMetadataCacheImpl();
            $metaDataCache->deleteAll();

            Shopware()->Models()->addAttribute('s_user_attributes', 'stetic', 'allowidentify', 'int(1)');        

            $metaDataCache  = Shopware()->Models()->getConfiguration()->getMetadataCacheImpl();
            $metaDataCache->deleteAll();

            Shopware()->Models()->generateAttributeModels(array('s_user_attributes'));

        } catch (Exception $exception) {

            Shopware()->Log()->Err("Stetic: Can't add attribites to shopware models. " . $exception->getMessage());
            throw new Exception("Stetic:  Can't add attribites to shopware models. " . $exception->getMessage());

        }
    }

    /**
     * Creates needed snippets in database
     * Called on install.
     *
     * @return void
     */
    public function createSnippets()
    {
        try {

            $repository = Shopware()->Models()->getRepository('Shopware\Models\Shop\Locale');
            $en   = array_shift($repository->findBy(array('locale' => 'en_GB')));
            $en   = $en->getId();
            $shop_id = Shopware()->Models()->getRepository('Shopware\Models\Shop\Shop')->getDefault()->getId();
            
            Shopware()->Db()->query(
               "INSERT IGNORE INTO s_core_snippets SET
               namespace   = 'frontend/register/index',
               shopID      = '{$shop_id}',
               localeID   =  '{$en}',
               name      = 'RegisterLabelIdentifyCheckbox',
               value      = 'I agree, that my data (user ID, name, email address and company name) will be used for statistical purposes with Stetic Web Analytics.'"
            );

        } catch (Exception $exception) {

            Shopware()->Log()->Err("Stetic: Can't create snippets. " . $exception->getMessage());
            throw new Exception("Stetic:  Can't create snippets. " . $exception->getMessage());
        }
    }

    public function uninstall()
    {
        try {

            Shopware()->Models()->removeAttribute('s_user_attributes', 'stetic', 'allowidentify');        

            $metaDataCache = Shopware()->Models()->getConfiguration()->getMetadataCacheImpl();
            $metaDataCache->deleteAll();

            Shopware()->Models()->generateAttributeModels(array('s_user_attributes'));

            $repository = Shopware()->Models()->getRepository('Shopware\Models\Shop\Locale');
            $en   = array_shift($repository->findBy(array('locale' => 'en_GB')));
            $en   = $en->getId();
            $shop_id = Shopware()->Models()->getRepository('Shopware\Models\Shop\Shop')->getDefault()->getId();

            Shopware()->Db()->query(
               "DELETE FROM s_core_snippets WHERE
               namespace   = 'frontend/register/index' AND
               shopID      = '{$shop_id}' AND
               localeID   =  '{$en}' AND
               name      = 'RegisterLabelIdentifyCheckbox'"
            );
        } 
        catch (Exception $e) {

            Shopware()->Log()->Err("Stetic: Uninstall failure. " . $exception->getMessage());
            throw new Exception("Stetic: Uninstall failure. " . $exception->getMessage());

        }

        return true;
    }

    public function enable()
    {
        return true;
    }

    public function disable()
    {
        return true;
    }

    /**
     * Returns whenever request is an ajax request
     *
     * @return boolean
     */
    function isAjax()
    {
        if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest')
        {
            return true;
        }
        return false;
    }

    /**
     * Log Helper
     * Logs a message to var/log in foursatts namespace
     *
     * @param string $msg
     */
    protected static function log()
    {
        $log_messages = array();
        $messages = func_get_args();
        foreach($messages as $message)
        {
            if(is_array($message) || is_object($message))
            {
                $log_messages[] = print_r($message, true);
            }
            else
            {
                $log_messages[] = (string)$message;
            }
        }
        Shopware()->Debuglogger()->info(implode(' ', $log_messages));
    }

}