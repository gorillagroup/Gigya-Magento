<?php

include_once __DIR__ . '/../sdk/GSSDK.php';
include_once __DIR__ . '/../sdk/gigyaCMS.php';

class Gigya_Social_Helper_Data extends Mage_Core_Helper_Abstract
{

    private $apiKey;
    private $apiSecret;
    private $apiDomain;
    private $userKey = null;
    private $userSecret = null;
    public  $utils;
    const CHARS_PASSWORD_LOWERS = 'abcdefghjkmnpqrstuvwxyz';
    const CHARS_PASSWORD_UPPERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const CHARS_PASSWORD_DIGITS = '23456789';
    const CHARS_PASSWORD_SPECIALS = '!$*-.=?@_';



    public function __construct() {
        $this->apiKey = Mage::getStoreConfig('gigya_global/gigya_global_conf/apikey');
        $this->apiSecret = Mage::getStoreConfig('gigya_global/gigya_global_conf/secretkey');
        $this->apiDomain = Mage::getStoreConfig('gigya_global/gigya_global_conf/dataCenter');
        $this->userKey = Mage::getStoreConfig('gigya_global/gigya_global_conf/userKey');
        $this->userSecret = Mage::getStoreConfig('gigya_global/gigya_global_conf/userSecret');
        $this->utils = new GigyaCMS($this->apiKey, $this->apiSecret, $this->apiDomain, $this->userSecret, $this->userKey);
    }
    public function _getPassword($length = 8)
    {
        $chars = self::CHARS_PASSWORD_LOWERS
            . self::CHARS_PASSWORD_UPPERS
            . self::CHARS_PASSWORD_DIGITS
            . self::CHARS_PASSWORD_SPECIALS;
        $str = Mage::helper('core')->getRandomString($length, $chars);
        return 'Gigya_' . $str;
    }

    public function notifyRegistration($gigyaUid, $siteUid)
    {
        $params = array(
            'UID' => $gigyaUid,
            'siteUID' => $siteUid,
        );
        try {
            $res = $this->_gigya_api('notifyRegistration', $params);
        } catch (Exception $e) {
            $code = $e->getCode();
            $message = $e->getMessage();
            Mage::logException($e);
        }
    }

    public function notifyLogin($siteUid, $newUser = 'false', $userInfo = array())
    {
        $params = array(
            'siteUID' => $siteUid,
            'newUser' => $newUser,
        );
        if (!empty($userInfo)) {
            $params['userInfo'] = Mage::helper('core')->jsonEncode($userInfo);
        }
        try {
            $res = $this->_gigya_api('notifyLogin', $params);
            if (is_object($res) && $res->getErrorCode() === 0) {
                setcookie($res->getString("cookieName"), $res->getString("cookieValue"), 0, $res->getString("cookiePath"), $res->getString("cookieDomain"));
            } else {
                Mage::logException($res);
            }
        } catch (Exception $e) {
            $code = $e->getCode();
            $message = $e->getMessage();
            Mage::logException($e);
        }
    }

    public function notifyLogout($siteUid)
    {
        $params = array(
            'siteUID' => $siteUid,
        );
        try {
            $this->_gigya_api('logout', $params);
        } catch (Exception $e) {
            $code = $e->getCode();
            $message = $e->getMessage();
            Mage::logException($e);
        }
    }

    public function deleteAccount($gigyaUid)
    {
        $params = array(
            'UID' => $gigyaUid,
        );
        try {
            $res = $this->_gigya_api('deleteAccount', $params);
        } catch (Exception $e) {
            $code = $e->getCode();
            $message = $e->getMessage();
            Mage::logException($e);
        }
    }


    /**
     * Helper function that handles Gigya API calls.
     *
     * @param mixed $method
     *   The Gigya API method.
     * @param mixed $params
     *   The method parameters.
     *
     * @return array
     *   The Gigya response.
     */
    public function _gigya_api($method, $params)
    {
        $data_center = Mage::getStoreConfig('gigya_global/gigya_global_conf/dataCenter');
        $data_center = !empty($data_center) ? $data_center : NULL;
        $apiKey = Mage::getStoreConfig('gigya_global/gigya_global_conf/apikey');
        $secretkey = Mage::getStoreConfig('gigya_global/gigya_global_conf/secretkey');
        $request = new GSRequest($apiKey, $secretkey, 'socialize.' . $method);
        if ($data_center !== NULL) {
            $request->setAPIDomain($data_center);
        }
        $params['format'] = 'json';
        foreach ($params as $param => $val) {
            $request->setParam($param, $val);
        }
        try {
            $response = $request->send();
            // If wrong data center resend to right one
            if ($response->getErrorCode() == 301001) {
                $data = $response->getData();
                $domain = $data->getString('apiDomain', NULL);
                if ($domain !== NULL) {
                    Mage::getModel('core/config')->saveConfig('gigya_global/gigya_global_conf/dataCenter', $domain);
                    $this->_gigya_api($method, $params);
                } else {
                    $ex = new Exception("Bad apiDomain return");
                    throw $ex;
                }
            } elseif ($response->getErrorCode() !== 0) {
                $exp = new Exception($response->getErrorMessage(), $response->getErrorCode());
                throw $exp;
            }
        } catch (Exception $e) {
            $code = $e->getCode();
            $message = $e->getMessage();
            Mage::log($message);
            return $code;
        }

        return $response;
    }

    public function getPluginConfig($pluginName, $format = 'json', $feed = FALSE)
    {
        $config = Mage::getStoreConfig($pluginName);
        foreach ($config as $key => $value) {
            //fix the magento yes/no as 1 or 0 so it would work in as true/false in javascript
            if ($value === '0' || $value === '1') {
                $config[$key] = ($value) ? true : false;
            }
        }
        // New comments can be override in advanced config
        if ($pluginName == 'gigya_comments/gigya_comments_conf') {
            $config['version'] = 2;
        }
        if (!empty($config['advancedConfig'])) {
            $advConfig = $this->_confStringToArry($config['advancedConfig']);
            foreach ($advConfig as $key => $val) {
                $advConfig[$key] = $this->_string_to_bool($val);
            }
            $config = $advConfig + $config;
        }
        unset($config['advancedConfig']);
        if ($feed === TRUE) {
            $config['privacy'] = Mage::getStoreConfig('gigya_activityfeed/gigya_activityfeed_conf/privacy');
        }
        if ($format === 'php') {
            return $config;
        }
        return Mage::helper('core')->jsonEncode($config);
    }

    public function getPluginContainerID($pluginName)
    {
        return Mage::getStoreConfig($pluginName . '/containerID');
    }

    public function isPluginEnabled($pluginName)
    {
        return Mage::getStoreConfig($pluginName . '/enable');
    }

    public function isShareBarEnabled($place)
    {
        return Mage::getStoreConfig('gigya_share/gigya_sharebar/enable_' . $place);
    }

    public function isShareActionEnabled($place)
    {
        return Mage::getStoreConfig('gigya_share/gigya_share_action/enable_' . $place);
    }

    public function getExtensionVersion()
    {
        return (string)Mage::getConfig()->getNode()->modules->Gigya_Social->version;
    }

    public function getUserMode()
    {

    }

    public function _confStringToArry($str)
    {
        $lines = array();
        $str = str_replace("\r\n", "\n", $str);
        $values = explode("\n", $str);
        //some clean up
        $values = array_map('trim', $values);
        $values = array_filter($values, 'strlen');
        foreach ($values as $value) {
            preg_match('/(.*)\|(.*)/', $value, $matches);
            $lines[$matches[1]] = $matches[2];
        }
        return $lines;
    }

    public function _string_to_bool($str)
    {
        if ($str === 'true' || $str === 'false') {
            return (bool)$str;
        }
        return $str;
    }

    public function call($method, $params)
    {
        return $this->utils->call($method, $params);
    }

    public function  getUtils() {
        return $this->utils;
    }
}
