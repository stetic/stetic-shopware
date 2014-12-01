<?php

/**
 * Stetic Analytics Shopware Plugin
 *
 * @category    Stetic
 * @package     Stetic_Analytics
 * @copyright   Copyright (c) 2014 Stetic (https://www.stetic.com/)
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Shopware\Stetic;

use \Enlight_Controller_Request_RequestHttp as RequestHttp;
use \Enlight_View_Default as View;

class Stetic
{
    private $request = null;
    private $view = null;
    private $config = null;

    /**
     * Constructor
     *
     * @param RequestHttp $request
     * @param View $view
     * @param Config $config
     *
     */
    public function __construct(RequestHttp $request, View $view, Config $config)
    {
        $this->request    = $request;
        $this->view        = $view;
        $this->config    = $config;
    }

    /**
     * Returns HTML to identify an user
     *
     * @return string
     */
    public function identify()
    {
        $viewAssign = $this->view->getAssign();
        
        if(Shopware()->Session()->sUserId && $this->config->identifyLoggedin)
        {

            if($this->config->userMustAllowidentify)
            {
                $sql = "SELECT stetic_allowidentify
                                FROM s_user_attributes a, s_user u
                                WHERE u.id = a.userID
                                AND u.id = ?";
                $userAllowidentify = Shopware()->Db()->fetchOne($sql, array(Shopware()->Session()->sUserId));

                if($userAllowidentify != '1')
                {
                    return '';
                }
            }
            if(Shopware()->Session()->steticUserId && Shopware()->Session()->steticUserId == Shopware()->Session()->sUserId && Shopware()->Session()->steticIdentify)
            {
                return gzuncompress(base64_decode(Shopware()->Session()->steticIdentify));
            }

            $builder = Shopware()->Models()->createQueryBuilder();
            $builder->select(array(
                    'customer.id as id',
                    'customer.firstLogin as firstlogin',
                    'customerBilling.number as customerNumber',
                    "CONCAT(CONCAT(customerBilling.firstName, ' '), customerBilling.lastName) as name",
                    'customerBilling.company as company',
                    'customer.email as email',
                    'customerBilling.city as city',
                    'billingCountry.isoName as country',
                    'customerBilling.phone as phone',
                    'customerGroup.name as customer_group'
                ))
                ->from('Shopware\Models\Customer\Customer', 'customer')
                ->innerJoin('customer.group', 'customerGroup')
                ->innerJoin('customer.billing', 'customerBilling')
                ->innerJoin('Shopware\Models\Country\Country', 'billingCountry', 'WITH', 'billingCountry.id=customerBilling.countryId')
                ->where('customer.id = ?1')
                ->setParameter(1, Shopware()->Session()->sUserId);

            $customer = $builder->getQuery()->getOneOrNullResult(\Doctrine\ORM\AbstractQuery::HYDRATE_ARRAY);

            if($customer)
            {
                $identify = array(
                    'id' => $customer['id'],
                    'name' => $customer['name'],
                    'email' => $customer['email'],
                    'company' => $customer['company'],
                    'city' => $customer['city'],
                    'country' => ucfirst(strtolower($customer['country'])),
                    'phone' => $customer['phone'],
                    'number' => $customer['customerNumber'],
                    'group' => $customer['customer_group'],
                    'created_at' => $customer['firstlogin']->format('Y-m-d H:i:s'),
                );

                $result = '_fss.identify = ' . json_encode($identify) . ';' . PHP_EOL;

                Shopware()->Session()->steticUserId = Shopware()->Session()->sUserId;
                Shopware()->Session()->steticIdentify = base64_encode(gzcompress($result));

                return $result;
            }
        }
    }

    /**
     * Registers events onPostDispatch
     *
     */
    public function registerPostEvents()
    {
        $controller = $this->request->getControllerName();
        $action = $this->request->getActionName();
        $viewAssign = $this->view->getAssign();

//        self::log("getEvents", $controller, $action, $this->request->getParams());

        $events_html = array();

        /***
        * Cart add (without JS)
        */
        if( $controller == "checkout" && $action == "cart" && $this->request->getParam('action') == 'addArticle' && 
            $this->request->getParam('sAdd') && $this->config->cartTracking )
        {

            $product = $viewAssign['sArticle'];

            $product_properties = $this->getProductProperties($product);
            $product_properties['quantity'] = $this->request->getParam('sQuantity');

            $this->registerEvent('basket', array("product" => $product_properties));
        }
        /***
        * Cart update
        */
        elseif( $controller == "checkout" && $action == "cart" && $this->request->getParam('action') == 'changeQuantity' && 
            $this->request->getParam('sArticle') && $this->config->cartTracking )
        {

            $sql= "SELECT articleID FROM s_order_basket WHERE id = ?";
            $orderBasket = Shopware()->Db()->fetchRow($sql, array($this->request->getParam('sArticle')));            
            $product = Shopware()->Modules()->Articles()->sGetArticleById($orderBasket['articleID']);

            $product_properties = $this->getProductProperties($product);
            $product_properties['quantity'] = $this->request->getParam('sQuantity');

            $this->registerEvent('basket_update', array("product" => $product_properties));
        }
        /***
        * Cart premium add
        */
        elseif( $controller == 'checkout' && $action == 'cart' && $this->request->getParam('action') == 'addPremium' && 
            $this->request->getParam('sAddPremium') && $this->config->cartTracking )
        {
            $articleID = Shopware()->Modules()->Articles()->sGetArticleIdByOrderNumber($this->request->getParam('sAddPremium'));
            $product = Shopware()->Modules()->Articles()->sGetArticleById($articleID);
            
            $this->registerEvent('basket_premium', array(
                'product' => $this->getProductProperties($product),
            ));
        }
        /***
        * Cart coupon delete
        */
        elseif( $controller == "checkout" && $action == "cart" && $this->request->getParam('action') == 'deleteArticle' && $this->config->cartTracking )
        {
            if($this->request->getParam('sDelete') == 'voucher')
            {
                $this->registerEvent('coupon_remove');
            }
        }
        /***
        * Cart coupon add
        */
        elseif( $controller == "checkout" && $action == "cart" && $this->request->getParam('action') == 'addVoucher' && $this->config->cartTracking )
        {

            $event = ($request['remove']) ? 'coupon_remove' : 'coupon';
            $status = 'invalid';

            $event_properties = array(
                   'code' => $this->request->getParam('sVoucher'),
               );

            $this->registerEvent('coupon', $event_properties);
        }
        /***
        * Account register
        */
        elseif( $this->request->getParam('controller') == 'register' && $this->request->getParam('action') == 'saveRegister' && $this->config->accountCreateTracking )
        {
            $register = $this->request->getParam('register');

            $this->registerEvent('account_create', array(
                'firstname' => $register['personal']['firstname'],
                'lastname' => $register['personal']['lastname'],
                'email' => $register['personal']['email'],
            ));
        }
        /***
        * Checkout index
        */
        elseif( $controller == 'checkout' && $action == "confirm" && $this->config->orderTracking )
        {
            $this->registerEvent('checkout_index');
        }
        /***
        * Checkout
        */
        elseif( $controller == "checkout" && $action == "finish" && $viewAssign["sOrderNumber"] && $this->config->orderTracking )
        {
            $total = (isset($viewAssign["sAmountWithTax"])) ? $viewAssign["sAmountWithTax"] : $viewAssign["sAmount"];

            $order_properties = array(
                "id" => $viewAssign["sOrderNumber"],
                "total" => (float)str_replace(",", ".", $total),
                "quantity" => 0.00,
                "weight" => 0.00,
                  "discount" => 0.00,
                "shipping" => array("type" => $viewAssign["sDispatch"]["name"], "amount" => (float)$viewAssign["sBasket"]['sShippingcostsWithTax']),
            );

            if(isset($viewAssign["sPayment"]) && isset($viewAssign["sPayment"]["description"]))
            {
                $order_properties['payment'] = $viewAssign["sPayment"]["description"];
            }

            $products = array();

            foreach($viewAssign["sBasket"]["content"] as $item)
            {
                if($item['modus'] == 4)
                {
                    $order_properties['discount'] += (float)str_replace(",", ".", $item['price']);
                }
                elseif($item['modus'] == 2)
                {
                    $order_properties['discount'] += (float)str_replace(",", ".", $item['price']);
                    $order_properties['coupon'] = $item['ordernumber'];
                }
                else
                {
                    if(isset($item["articleID"]))
                    {
                        $product_for_products = array(
                            "id" => $item["articleID"],
                            "name" => $item['articlename'],
                            "sku" => $item['ordernumber'],
                            "quantity" => (int)$item["quantity"],
                            "price" => (float)str_replace(",", ".", $item['price']),
                            "revenue" => (float)str_replace(",", ".", $item['amount']),
                        );
                        $categories = $this->getProductCategories($item['articleID']);
                        if(count($categories))
                        {
                            $product_for_products["category"] = $categories;
                        }
                        $products[] = $product_for_products;
                        $order_properties['quantity'] += (int)$item["quantity"];
                        if(isset($item['additional_details']) && isset($item['additional_details']['weight']))
                        {
                            $order_properties['weight'] += (float)$item['additional_details']['weight'];
                        }
                    }
                }
            }

            $order_properties['products'] = $products;
            $order_properties['revenue'] = (float)$total_revenue;

            $this->registerEvent('order', $order_properties);

        }
        /***
        * Logout
        */
        elseif( $controller == 'account' && $action == 'ajax_logout' && $this->config->loginTracking )
        {
            $this->registerEvent('logout');
        }
        /***
        * Forgot password
        */
        elseif( $controller == 'account' && $action == 'password' && $this->config->forgotPasswordTracking )
        {
            $email = $this->request->getParam('email');
            if(!empty($email))
            {
                $this->registerEvent('forgot password', array("email" => $email));
            }
        }
        /***
        * Newsletter change
        */
        elseif( $controller == 'account' && $action == 'index' &&  $this->request->getParam('action') == 'saveNewsletter' && $this->config->newsletterTracking )
        {
            $event = ($this->request->getParam('newsletter') == '1') ? 'subscribe' : 'unsubscribe';
            
            $email = ( isset($viewAssign['sUserData']) && isset($viewAssign['sUserData']['additional'])  && 
                        isset($viewAssign['sUserData']['additional']['user']) && isset($viewAssign['sUserData']['additional']['user']['email']) )
                        ? $viewAssign['sUserData']['additional']['user']['email'] : "";

            $this->registerEvent('newsletter_' . $event, array("email" => $email));
        }
        /***
        * Search
        */
        elseif( $controller == 'search'  && $this->config->searchTracking )
        {
            $this->registerEvent('search', array(
                'query' => $this->request->getParam('sSearch'),
            ));
        }
        /***
        * Contact form post
        */
        elseif( $controller == 'forms' && $action == 'index' && $this->request->getParam('Submit') && $this->config->contactFormTracking )
        {
            $comment = $this->request->getParam('kommentar');

            $this->registerEvent('contact_form', array(
                'name' => trim($this->request->getParam('vorname') . ' ' . $this->request->getParam('nachname')),
                'email' => $this->request->getParam('email'),
                'phone' => $this->request->getParam('telefon'),
                'comment' => ($this->request->getParam('inquiry')) ? $this->request->getParam('inquiry') : $this->request->getParam('kommentar'),
            ));
        }
        /***
        * Newsletter actions
        */
        elseif( $controller == 'newsletter' && $action == 'index' && $this->request->getParam('subscribeToNewsletter') && $this->config->newsletterTracking )
        {
            $event = ($this->request->getParam('subscribeToNewsletter') == -1) ? 'unsubscribe' : 'subscribe';

            $this->registerEvent('newsletter_' . $event, array(
                'email' => $this->request->getParam('newsletter'),
            ));
        }
        /***
        * Compare add
        */
        elseif( $controller == 'compare' && $action == 'add_article' && $this->request->getParam('articleID') && $this->config->productCompareTracking )
        {
            $product = Shopware()->Modules()->Articles()->sGetArticleById($this->request->getParam('articleID'));

            $this->registerEvent('compare', array(
                'product' => $this->getProductProperties($product),
            ));
        }
        /***
        * Compare remove
        */
        elseif( $controller == 'compare' && $action == 'index' && $this->request->getParam('action') == 'delete_article' && 
            $this->request->getParam('articleID') && $this->config->productCompareTracking )
        {
            $product = Shopware()->Modules()->Articles()->sGetArticleById($this->request->getParam('articleID'));

            $this->registerEvent('compare_remove', array(
                'product' => $this->getProductProperties($product),
            ));
        }
        /***
        * Product review
        */
        elseif( $controller == 'detail' && $action == 'index' && $this->request->getParam('action') == 'rating' && 
            $this->request->getParam('sArticle')  && $this->request->getParam('Submit') && $this->config->productReviewTracking )
        {
            $product = Shopware()->Modules()->Articles()->sGetArticleById($this->request->getParam('sArticle'));

            $this->registerEvent('product_review', array(
                'nickname' => $this->request->getParam('sVoteName'),
                'email' => $this->request->getParam('sVoteMail'),
                'title' => $this->request->getParam('sVoteSummary'),
                'comment' => $this->request->getParam('sVoteComment'),
                'rating' => $this->request->getParam('sVoteStars'),
                'product' => $this->getProductProperties($product),
            ));
        }
        /***
        * Product review conform
        */
        elseif( $controller == 'detail' && $action == 'index' && $this->request->getParam('action') == 'rating' && 
            $this->request->getParam('sConfirmation')  && $this->request->getParam('sArticle') && $this->config->productReviewTracking )
        {
            $product = Shopware()->Modules()->Articles()->sGetArticleById($this->request->getParam('sArticle'));

            $this->registerEvent('product_review_confirm', array(
                'product' => $this->getProductProperties($product),
            ));
        }
        /***
        * Wishlist add
        */
        elseif( $controller == 'note' && $action == 'index' && $this->request->getParam('action') == 'add' && 
            $this->request->getParam('ordernumber') && $this->config->wishlistTracking )
        {
            $articleID = Shopware()->Modules()->Articles()->sGetArticleIdByOrderNumber($this->request->getParam('ordernumber'));
            $product = Shopware()->Modules()->Articles()->sGetArticleById($articleID);

            $this->registerEvent('wishlist', array(
                'product' => $this->getProductProperties($product),
            ));
        }
        /***
        * Wishlist delete
        */
        elseif( $controller == 'note' && $action == 'index' && $this->request->getParam('action') == 'delete' && 
            $this->request->getParam('ordernumber') && $this->config->wishlistTracking )
        {
            $articleID = Shopware()->Modules()->Articles()->sGetArticleIdByOrderNumber($this->request->getParam('ordernumber'));
            $product = Shopware()->Modules()->Articles()->sGetArticleById($articleID);

            $this->registerEvent('wishlist_remove', array(
                'product' => $this->getProductProperties($product),
            ));
        }
        /***
        * Tell a friend
        */
        elseif( $controller == 'tellafriend' && $action == 'index' && $this->request->getParam('sMailTo') == '1' && 
            $this->request->getParam('sArticle') && $this->config->sendfriendTracking )
        {
            $product = Shopware()->Modules()->Articles()->sGetArticleById($this->request->getParam('sArticle') );

            $this->registerEvent('sendfriend', array( 'product' => $this->getProductProperties($product) ));
        }

    }

    /**
     * Registers an basket delete event for current instance
     *
     * @return void
     */
    public function registerBasketDelete()
    {
        if(!$this->config->cartTracking)
        {
            return;
        }

        $viewAssign = $this->view->getAssign();

        $sDelete = $this->request->getParam('sDelete');
        if($sDelete)
        {
            $sql= "SELECT articleID, quantity, articlename, ordernumber, price FROM s_order_basket WHERE id = ?";
            $product = Shopware()->Db()->fetchRow($sql, array($sDelete));
            if($product)
            {
                $categories = $this->getProductCategories($product['articleID']);

                $this->registerEvent('basket_remove', array("product" => array(
                    "id" => $product['articleID'],
                    "name" => $product['articlename'],
                    "sku" => $product['ordernumber'],
                    "price" => $product['price'],
                    "category" => $categories,
                    "quantity" => $product['quantity']
                )));
            }
        }
    }

    /**
     * Registers an wishlist delete event for current instance
     *
     * @return void
     */
    public function registerWishlistDelete()
    {
        if(!$this->config->wishlistTracking)
        {
            return;
        }

        $viewAssign = $this->view->getAssign();

        $sDelete = $this->request->getParam('sDelete');
        if($sDelete)
        {
            $sql= "SELECT articleID FROM s_order_notes WHERE id = ?";

            $orderNote = Shopware()->Db()->fetchRow($sql, array($sDelete));
            if($orderNote)
            {
                $product = Shopware()->Modules()->Articles()->sGetArticleById($orderNote['articleID']);

                if($product)
                {
                    $categories = $this->getProductCategories($product['articleID']);

                    $this->registerEvent('wishlist_remove', array("product" => array(
                        "id" => $product['articleID'],
                        "name" => $product['articlename'],
                        "sku" => $product['ordernumber'],
                        "price" => $product['price'],
                        "category" => $categories,
                    )));
                }
            }
        }
    }
    
    /**
     * Register an event
     *
     * @param string $event
     * @param array $properties
     * @return void
     */
    public function registerEvent($event, $properties)
    {
//        self::log("registerEvent", $event, $properties);
        
        if(!isset(Shopware()->Session()->steticEvents) || !is_array(Shopware()->Session()->steticEvents))
        {
            Shopware()->Session()->steticEvents = array();
        }

        Shopware()->Session()->steticEvents[] = array("event" => $event, "properties" => $properties);
    }
    
    /**
     * Returns the tracking code for all registered events as HTML
     *
     * @return string
     */
    public function getRegisteredEventsHtml()
    {
        $events_html = array();

        $events = Shopware()->Session()->steticEvents;

        if(is_array($events) && !empty($events))
        {
            foreach($events as $event)
            {
                if($event['event'] == 'login' && empty(Shopware()->Session()->sUserId))
                {
                    $event['event'] = 'login_failed';
                }
                if($event['event'] == 'account_create' && empty(Shopware()->Session()->sUserId))
                {
                    continue;
                }
                elseif($event['event'] == 'account_create')
                {
                    try
                    {
                        
                        $allow_identify = (Shopware()->Session()->steticUserNewAllowIdentify) ? 1 : 0;

                        $sql = "INSERT INTO s_user_attributes(userID,stetic_allowidentify)
                                       VALUES ( ?, ?)
                                       ON DUPLICATE KEY
                                       UPDATE userID = ?, stetic_allowidentify = ?";

                        Shopware()->Db()->query($sql, array(Shopware()->Session()->sUserId, $allow_identify, Shopware()->Session()->sUserId, $allow_identify));
                    }
                    catch (Exception $exception)
                    {
                    }


                }
                $events_html[] = $this->trackEvent($event['event'], $event['properties']);
            }
        }

        // Unset triggered events
        Shopware()->Session()->steticEvents = array();

        return implode("\n", $events_html);
    }
    
    /**
     * Returns data for the tracker view
     *
     * @param array $sArticle
     * @return mixed
     */
    public function getViewData()
    {
        $controller = $this->request->getControllerName();
        $viewAssign = $this->view->getAssign();

        if( $controller == "detail" && $this->config->cartTracking )
        {
            $product = $viewAssign['sArticle'];
            $data = array("product" => $this->getProductProperties($product));
            return $data;
        }
    }

    /**
     * Checks and hooks account views
     *
     */
    public function checkAccountViews()
    {
        $controller = $this->request->getControllerName();
        $action = $this->request->getActionName();
        $viewAssign = $this->view->getAssign();

        if($controller == 'register' && $action == 'index' && $this->config->userMustAllowidentify)
        {
            $this->view->extendsTemplate('frontend/plugins/stetic/register.tpl');
        }
        elseif($controller == 'account' && $action == 'index' && $this->config->userMustAllowidentify)
        {
            $sql = "SELECT stetic_allowidentify
                            FROM s_user_attributes a, s_user u
                            WHERE u.id = a.userID
                            AND u.id = ?";
            $this->view->userAllowidentify = Shopware()->Db()->fetchOne($sql, array(Shopware()->Session()->sUserId));

            $this->view->extendsTemplate('frontend/plugins/stetic/account_index.tpl');

            $post = Shopware()->Modules()->Admin()->sSYSTEM->_POST;

            if($this->request->isPost() && $post['steticAction'] == 'save')
            {
                $allow_identify = (isset($post['allowidentify']) && $post['allowidentify'] == '1') ? 1 : 0;
                $this->view->userAllowidentify = $allow_identify;

                try
                {

                    $sql = "INSERT INTO s_user_attributes(userID,stetic_allowidentify)
                                   VALUES ( ?, ?)
                                   ON DUPLICATE KEY
                                   UPDATE userID = ?, stetic_allowidentify = ?";

                    Shopware()->Db()->query($sql, array(Shopware()->Session()->sUserId, $allow_identify, Shopware()->Session()->sUserId, $allow_identify));
                }
                catch (Exception $exception)
                {
                }
            }
        }
    }


    /**
     * Build product properties array
     *
     * @param array $sArticle
     * @return mixed
     */
    public function getProductProperties($product)
    {
        if($product && count($product) > 0)
        {
            $result_product = array(
                "id" => $product['articleID'],
                "name" => ($product['articlename']) ? $product['articlename'] : $product['articleName'],
                "sku" => $product['ordernumber'],
                "price" => (float)str_replace(",", ".", $product['price'])
            );

            $categories = $this->getProductCategories($product['articleID']);
            if(count($categories))
            {
                $result_product["category"] = $categories;
            }

            return $result_product;
        }

        return false;
    }

    /**
     * Returns product categories
     *
     * @param int $articleID
     * @return array
     */
    protected function getProductCategories($articleID)
    {
        $categories = array();
        if($articleID && $articleID > 0)
        {
            $articleDetail = Shopware()->Models()->getRepository('Shopware\Models\Article\Detail')->findOneBy(array("articleId" => $articleID));

            if($articleDetail)
            {
                $articleCategories = $articleDetail->getArticle()->getCategories();
                foreach($articleCategories as $category)
                {
                    $categories[] = $category->getName();
                }

            }
            $categories = array_unique($categories);
        }

        return $categories;
    }

    /**
     * Returns html for block to track given event
     *
     * @param string $event
     * @param array $properties
     * @return string
     */
    protected function trackEvent($event, $properties = array())
    {
        $html = "stetic.track('{$event}'";
        if(is_array($properties) && !empty($properties))
        {
            $html .= ", " . json_encode($properties);
        }
        $html .= ");";
        return $html . PHP_EOL;
    }

    /**
     * Log Helper
     * Logs a message
     *
     * @param mixed $msgs
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

