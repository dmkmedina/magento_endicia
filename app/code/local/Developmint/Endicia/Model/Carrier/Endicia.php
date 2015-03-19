<?php

/**
 * Our test shipping method module adapter
 */
class Developmint_Endicia_Model_Carrier_Endicia extends Mage_Shipping_Model_Carrier_Abstract
{
    /**
     * Ounces in one pound for conversion
     */
    const OUNCES_POUND = 16;

    const FIRST_CLASS_SHORT_NAME = 'First Class Package International Service';
    const FIRST_CLASS_LONG_NAME = 'USPS First-Class Package International Service (2-6 Weeks)';

    const PRIORITY_SHORT_NAME = 'Priority Mail International';
    const PRIORITY_LONG_NAME = 'USPS Priority Mail International (6-10 Business days)';

    const PRIORITY_EXPRESS_SHORT_NAME = 'Priority Mail Express International';
    const PRIORITY_EXPRESS_LONG_NAME = 'USPS Priority Mail Express International (3-5 Business days)';

    /**
     * unique internal shipping method identifier
     *
     * @var string [a-z0-9_]
     */
    protected $_code = 'endicia';

    /**
     * Collect rates for this shipping method based on information in $request
     *
     * @param Mage_Shipping_Model_Rate_Request $data
     * @return Mage_Shipping_Model_Rate_Result
     */
    public function collectRates(Mage_Shipping_Model_Rate_Request $request)
    {
        // skip if not enabled
        if (!Mage::getStoreConfig('carriers/'.$this->_code.'/active')) {
            return false;
        }

        /**
         * here we are retrieving shipping rates from external service
         * or using internal logic to calculate the rate from $request
         * you can see an example in Mage_Usa_Model_Shipping_Carrier_Ups::setRequest()
         */

        // get necessary configuration values
        //$handling = Mage::getStoreConfig('carriers/'.$this->_code.'/handling');

        // this object will be returned as result of this method
        // containing all the shipping rates of this method
        //$result = Mage::getModel('shipping/rate_result');

        if (!$this->_isUSCountry($request->getDestCountryId())) {
            $result = $this->_getEndiciaRates($request);
        }else {
            $result = Mage::getModel('shipping/rate_result');
        }

        //Mage::log($result, null, 'endicia.log');

        // $response is an array that we have
        /*foreach ($response as $rMethod) {
            // create new instance of method rate
            $method = Mage::getModel('shipping/rate_result_method');

            // record carrier information
            $method->setCarrier($this->_code);
            $method->setCarrierTitle(Mage::getStoreConfig('carriers/'.$this->_code.'/title'));

            // record method information
            $method->setMethod($rMethod['code']);
            $method->setMethodTitle($rMethod['title']);

            // rate cost is optional property to record how much it costs the vendor to ship
            $method->setCost($rMethod['amount']);

            // in our example handling is fixed amount that is added to cost
            // to receive price the customer will pay for shipping method.
            // it could be as well percentage:
            /// $method->setPrice($rMethod['amount']*$handling/100);
            $method->setPrice($rMethod['amount']);

            // add this rate to the result
            $result->append($method);
        }*/

        return $result;
    }

    /**
     * This method is used when viewing / listing Shipping Methods with Codes programmatically
     */
    public function getAllowedMethods() {
        return array($this->_code => $this->getConfigData('name'));
    }

    protected function _getEndiciaRates(Mage_Shipping_Model_Rate_Request $request) {
        $shipping_result = Mage::getModel('shipping/rate_result');

        try{
            /* **** ENDICIA LABEL SERVER WEB ADDRESS **** */
            //$client = new SoapClient("https://www.envmgr.com/LabelService/EwsLabelService.asmx?wsdl"); // Test Server
            $client = new SoapClient(Mage::getStoreConfig('carriers/'.$this->_code.'/gateway_url')); // Production Server

            /* **** CREATE THE XML REQUEST FOR ENDICIA LABEL SERVER**** */

            $weight = $request->getPackageWeight();;
            $weightOunces = round(($weight-floor($weight)) * self::OUNCES_POUND, 1);
            $postcode = Mage::getStoreConfig(Mage_Shipping_Model_Config::XML_PATH_ORIGIN_POSTCODE, Mage::app()->getStore()->getId());

            //Mage::log('weight in ounces: ' . $weightOunces, null, 'endicia.log');
            //Mage::log('postcode: ' . $postcode, null, 'endicia.log');

            $data = array
            (
                'PostageRatesRequest' => array
                (
                    'MailClass' => 'International',

                    'RequesterID' => 'ABCD',

                    'CertifiedIntermediary' => array(
                        'AccountID' => Mage::getStoreConfig('carriers/'.$this->_code.'/account'),
                        'PassPhrase' => Mage::getStoreConfig('carriers/'.$this->_code.'/password')
                    ),

                    'DateAdvance' => 0,

                    'WeightOz' => $weightOunces,

                    'MailpieceShape' => 'Parcel',

                    'Services' => array
                    (
                        'DeliveryConfirmation' => 'OFF',
                        'SignatureConfirmation' => 'OFF'
                    ),

                    'InsuredValue' => 0,
                    'CODAmount' => 0,
                    'RegisteredMailValue' => 0,

                    'FromPostalCode' => $postcode,

                    'ToPostalCode' => '',

                    'ToCountryCode' => $request->getDestCountryId()
                )
            );


            $result = $client->CalculatePostageRates($data);

            $errorMessage = $result->PostageRatesResponse->ErrorMessage;

            if (!$errorMessage){
                $postagePriceArray = $result->PostageRatesResponse->PostagePrice;

                foreach ($postagePriceArray as $postagePrice) {
                    $service_name = $postagePrice->Postage->MailService;

                    if ($service_name == self::PRIORITY_SHORT_NAME) {

                        $rate = Mage::getModel('shipping/rate_result_method');
                        $rate->setMethod($postagePrice->Postage->MailService);
                        $rate->setMethodTitle(self::PRIORITY_LONG_NAME);

                    }else if ($service_name == self::FIRST_CLASS_SHORT_NAME) {

                        $rate = Mage::getModel('shipping/rate_result_method');
                        $rate->setMethod($postagePrice->Postage->MailService);
                        $rate->setMethodTitle(self::FIRST_CLASS_LONG_NAME);

                    }else if ($service_name == self::PRIORITY_EXPRESS_SHORT_NAME) {

                        $rate = Mage::getModel('shipping/rate_result_method');
                        $rate->setMethod($postagePrice->Postage->MailService);
                        $rate->setMethodTitle(self::PRIORITY_EXPRESS_LONG_NAME);

                    }else {
                        continue;
                    }

                    $rate->setCarrier('endicia');
                    $rate->setCarrierTitle($this->getConfigData('title'));
                    //$rate->setCost($postagePrice->Postage->Pricing);
                    $rate->setCost($postagePrice->Postage->TotalAmount);
                    $rate->setPrice($postagePrice->Postage->TotalAmount);
                    $shipping_result->append($rate);
                }

            } else {
                Mage::log('Error occurred while retrieving endicia rates: ' . $errorMessage, null, 'endicia.log');
            }

        }catch (Exception $e) {
            Mage::log('Error occurred while retrieving endicia rates. Caugh exception: ' . $e->getMessage(), null, 'endicia.log');
        }


        return $shipping_result;
    }


    /**
     * Check is Country U.S. Possessions and Trust Territories
     *
     * @param string $countyId
     * @return boolean
     */
    protected function _isUSCountry($countyId)
    {
        switch ($countyId) {
            case 'AS': // Samoa American
            case 'GU': // Guam
            case 'MP': // Northern Mariana Islands
            case 'PW': // Palau
            case 'PR': // Puerto Rico
            case 'VI': // Virgin Islands US
            case 'US'; // United States
                return true;
        }

        return false;
    }
}