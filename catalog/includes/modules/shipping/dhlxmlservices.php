<?php
/**
 * @package shippingMethod
 * @copyright Copyright 2003-2009 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: dhlxmlservices.php 20141012 bislewl
 */
/**
 * DHL Shipping Module class
 *
 */
class dhlxmlservices extends base {
  /**
   * Declare shipping module alias code
   *
   * @var string
   */
  var $code;
  /**
   * Shipping module display name
   *
   * @var string
   */
  var $title;
  /**
   * Shipping module display description
   *
   * @var string
   */
  var $description;
  /**
   * Shipping module icon filename/path
   *
   * @var string
   */
  var $icon;
  /**
   * Shipping module status
   *
   * @var boolean
   */
  var $enabled;
  /**
   * Shipping module list of supported countries (unique to USPS/DHL)
   *
   * @var array
   */
  var $types;
  /**
   * Constructor
   *
   * @return dhl
   */
  function dhlxmlservices() {
    global $order, $db, $template, $current_page_base;

    $this->code = 'dhlxmlservices';
    $this->title = MODULE_SHIPPING_DHL_TEXT_TITLE;
    $this->description = MODULE_SHIPPING_DHL_TEXT_DESCRIPTION;
    $this->sort_order = MODULE_SHIPPING_DHL_SORT_ORDER;
    $this->icon = $template->get_template_dir('shipping_dhl.gif', DIR_WS_TEMPLATE, $current_page_base,'images/icons'). '/' . 'shipping_dhl.gif';
    $this->tax_class = MODULE_SHIPPING_DHL_TAX_CLASS;
    $this->tax_basis = MODULE_SHIPPING_DHL_TAX_BASIS;

    // disable only when entire cart is free shipping
    if (zen_get_shipping_enabled($this->code)) {
      $this->enabled = ((MODULE_SHIPPING_DHL_STATUS == 'True') ? true : false);
    }

    if ($this->enabled) {
      // check MODULE_SHIPPING_DHL_HANDLING_METHOD is in
      $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_SHIPPING_DHL_HANDLING_METHOD'");
      if ($check_query->EOF) {
        $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Handling Per Order or Per Box', 'MODULE_SHIPPING_DHL_HANDLING_METHOD', 'Box', 'Do you want to charge Handling Fee Per Order or Per Box?', '6', '0', 'zen_cfg_select_option(array(\'Order\', \'Box\'), ', now())");
      }
    }

    if ( ($this->enabled == true) && ((int)MODULE_SHIPPING_DHL_ZONE > 0) ) {
      $check_flag = false;
      $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_SHIPPING_DHL_ZONE . "' and zone_country_id = '" . $order->delivery['country']['id'] . "' order by zone_id");
      while (!$check->EOF) {
        if ($check->fields['zone_id'] < 1) {
          $check_flag = true;
          break;
        } elseif ($check->fields['zone_id'] == $order->delivery['zone_id']) {
          $check_flag = true;
          break;
        }
        $check->MoveNext();
      }

      if ($check_flag == false) {
        $this->enabled = false;
      }
    }
  }
  /**
   * Get quote from shipping provider's API:
   *
   * @param string $method
   * @return array of quotation results
   */
  function quote($method = '') {
    global $_POST, $order, $shipping_weight, $shipping_num_boxes;

    $this->_dhlPiecesElement();
    $this->_dhlDutiableElement();
    $this->_dhlBkgElement();
    $this->_dhlFromElement(MODULE_SHIPPING_DHL_FROM_CNTRY, MODULE_SHIPPING_DHL_FROM_CITY, MODULE_SHIPPING_DHL_FROM_POSTAL);
    $this->_dhlToElement($order->delivery['country']['iso_code_2'], $order->delivery['city'], $order->delivery['postcode']);
    $this->_dhlRequestElement();
    
    $dhlQuote = $this->_dhlGetQuote();
    $bkgdetails = $dhlQuote->GetQuoteResponse->BkgDetails;
    
    $methods = array();
      $db_allowed_metods = explode(", ", MODULE_SHIPPING_DHL_TYPES);
      foreach($db_allowed_metods as $db_method){
          $allowed_methods[] = substr($db_method, 0,1);
      }
      foreach($bkgdetails->QtdShp as $detailed){
          if ((int)$detailed->ShippingCharge != 0){
              $title = ucwords(strtolower((string)$detailed->LocalProductName));
              $title = trim(str_replace('Nondoc', "", $title));
              $methods[] = array('id' => (string)$detailed->GlobalProductCode,
                           'title' => $title,
                           'cost' => ((string)$detailed->ShippingCharge) + (MODULE_SHIPPING_DHL_HANDLING_METHOD == 'Box' ? MODULE_SHIPPING_DHL_HANDLING * $shipping_num_boxes : MODULE_SHIPPING_DHL_HANDLING) );
     
          }
      }
      
    if ( (is_array($methods)) && (sizeof($methods) > 0) ) {
      switch (SHIPPING_BOX_WEIGHT_DISPLAY) {
        case (0):
        $show_box_weight = '';
        break;
        case (1):
        $show_box_weight = ' (' . $shipping_num_boxes . ' ' . TEXT_SHIPPING_BOXES . ')';
        break;
        case (2):
        $show_box_weight = ' (' . number_format($shipping_weight * $shipping_num_boxes,2) . TEXT_SHIPPING_WEIGHT . ')';
        break;
        default:
        $show_box_weight = ' (' . $shipping_num_boxes . ' x ' . number_format($shipping_weight,2) . TEXT_SHIPPING_WEIGHT . ')';
        break;
      
      }
      $this->quotes = array('id' => $this->code,
                            'module' => $this->title . $show_box_weight);

      
      
      
      

      $this->quotes['methods'] = $methods;

      if ($this->tax_class > 0) {
        $this->quotes['tax'] = zen_get_tax_rate($this->tax_class, $order->delivery['country']['id'], $order->delivery['zone_id']);
      }
    } else {
      $this->quotes = array('module' => $this->title,
                            'error' => 'We are unable to obtain a rate quote for DHL shipping.<br />Please contact the store if no other alternative is shown.');
    }

    if (zen_not_null($this->icon)) $this->quotes['icon'] = zen_image($this->icon, $this->title);

    return $this->quotes;
  }
  /**
   * check status of module
   *
   * @return boolean
   */
  function check() {
    global $db;
    if (!isset($this->_check)) {
      $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_SHIPPING_DHL_STATUS'");
      $this->_check = $check_query->RecordCount();
    }
    return $this->_check;
  }
  /**
   * Install this module
   *
   */
  function install() {
    global $db;
            
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable DHL Shipping', 'MODULE_SHIPPING_DHL_STATUS', 'True', 'Do you want to offer DHL shipping?', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('DHL Version', 'MODULE_SHIPPING_DHL_VERSION', '1.0.0', '', '6', '0', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sort order of display.', 'MODULE_SHIPPING_DHL_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', '6', '0', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Site ID', 'MODULE_SHIPPING_DHL_SITEID', 'CustomerTest', 'Provided by DHL', '6', '1', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Account Number', 'MODULE_SHIPPING_DHL_ACCOUNTNUMBER', '803921577', 'Provided by DHL', '6', '1', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Password', 'MODULE_SHIPPING_DHL_PASSWORD', 'alkd89nBV', 'Provided by DHL', '6', '1', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('From Country', 'MODULE_SHIPPING_DHL_FROM_CNTRY', 'US', '', '6', '2', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('From City', 'MODULE_SHIPPING_DHL_FROM_CITY', '', 'Leave blank if not needed for your country', '6', '2', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('From Postal Code', 'MODULE_SHIPPING_DHL_FROM_POSTAL', '', 'Leave blank if not needed for your country', '6', '2', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Handling Fee', 'MODULE_SHIPPING_DHL_HANDLING', '0', 'Handling fee for this shipping method.', '6', '3', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Handling Per Order or Per Box', 'MODULE_SHIPPING_DHL_HANDLING_METHOD', 'Box', 'Do you want to charge Handling Fee Per Order or Per Box?', '6', '3', 'zen_cfg_select_option(array(\'Order\', \'Box\'), ', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Tax Class', 'MODULE_SHIPPING_DHL_TAX_CLASS', '0', 'Use the following tax class on the shipping fee.', '6', '4', 'zen_get_tax_class_title', 'zen_cfg_pull_down_tax_classes(', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Tax Basis', 'MODULE_SHIPPING_DHL_TAX_BASIS', 'Shipping', 'On what basis is Shipping Tax calculated. Options are<br />Shipping - Based on customers Shipping Address<br />Billing Based on customers Billing address<br />Store - Based on Store address if Billing/Shipping Zone equals Store zone', '6', '4', 'zen_cfg_select_option(array(\'Shipping\', \'Billing\', \'Store\'), ', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Dutiable?', 'MODULE_SHIPPING_DHL_DUTIABLE', 'Y', 'Is your shipments subject to duty?', '6', '4', 'zen_cfg_select_option(array(\'Y\', \'N\'), ', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Currency', 'MODULE_SHIPPING_DHL_CURRENCY', 'USD', '', '6', '4', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Dimension Unit', 'MODULE_SHIPPING_DHL_DIM_UNIT', 'IN', '', '6', '4', 'zen_cfg_select_option(array(\'IN\', \'CM\'), ', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Weight Unit', 'MODULE_SHIPPING_DHL_WEIGTH_UNIT', 'LB', '', '6', '4', 'zen_cfg_select_option(array(\'LB\', \'KG\'), ', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Shipping Zone', 'MODULE_SHIPPING_DHL_ZONE', '0', 'If a zone is selected, only enable this shipping method for that zone.', '6', '19', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");
    $db->Execute("INSERT INTO " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Server', 'MODULE_SHIPPING_DHL_SERVER', 'Testing', 'Testing or Production Server?', '6', '19', 'zen_cfg_select_option(array(\'Testing\', \'Production\'), ', now())");
    
  }
  /**
   * Remove this module
   *
   */
  function remove() {
    global $db;
    $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key like 'MODULE\_SHIPPING\_DHL\_%' ");
  }
  /**
   * Build array of keys used for installing/managing this module
   *
   * @return array
   */
  function keys() {
      return array('MODULE_SHIPPING_DHL_STATUS','MODULE_SHIPPING_DHL_VERSION','MODULE_SHIPPING_DHL_SORT_ORDER','MODULE_SHIPPING_DHL_SITEID','MODULE_SHIPPING_DHL_PASSWORD','MODULE_SHIPPING_DHL_ACCOUNTNUMBER','MODULE_SHIPPING_DHL_SERVER','MODULE_SHIPPING_DHL_FROM_CNTRY','MODULE_SHIPPING_DHL_FROM_CITY','MODULE_SHIPPING_DHL_FROM_POSTAL','MODULE_SHIPPING_DHL_HANDLING','MODULE_SHIPPING_DHL_HANDLING_METHOD','MODULE_SHIPPING_DHL_TAX_CLASS','MODULE_SHIPPING_DHL_TAX_BASIS','MODULE_SHIPPING_DHL_DUTIABLE','MODULE_SHIPPING_DHL_CURRENCY','MODULE_SHIPPING_DHL_DIM_UNIT','MODULE_SHIPPING_DHL_WEIGTH_UNIT','MODULE_SHIPPING_DHL_ZONE');
  }
  
  function _dhlRequestElement(){
      $this->_dhlXMLReqElement = '<Request>
                                        <ServiceHeader>
                                            <MessageTime>'.date('c').'</MessageTime>
                                            <MessageReference>718688fff46f49d7cf9b67cd4'.rand(999,99999).'</MessageReference>
                                            <SiteID>'.MODULE_SHIPPING_DHL_SITEID.'</SiteID>
                                            <Password>'.MODULE_SHIPPING_DHL_PASSWORD.'</Password>
                                        </ServiceHeader>
                                 </Request>';
  }
  function _dhlFromElement($country,$city = '', $postcode = ''){
      $this->_dhlXMLFromElement = '<From>';
         $this->_dhlXMLFromElement .= '<CountryCode>'.$country.'</CountryCode>';
         if($postcode != ''){ $this->_dhlXMLFromElement .= '<Postalcode>'.$postcode.'</Postalcode>';}
         else{ $this->_dhlXMLFromElement .= '<Postalcode/>';}
         if($city != ''){ $this->_dhlXMLFromElement .= '<City>'.strtoupper($city).'</City>';}
         else{ $this->_dhlXMLFromElement .= '<City/>';}
      $this->_dhlXMLFromElement .= '</From>';
  }
  
  function _dhlToElement($country,$city = '', $postcode = ''){
      $this->_dhlXMLToElement = '<To>';
         $this->_dhlXMLToElement .= '<CountryCode>'.$country.'</CountryCode>';
         $this->_dhlXMLToElement .= '<Postalcode>'.$postcode.'</Postalcode>';
         if($city != '') {$this->_dhlXMLToElement .= '<City>'.strtoupper($city).'</City>';}
      $this->_dhlXMLToElement .= '</To>';
  }
  
  function _dhlBkgElement(){
    $this->_dhlXMLBkgElement =  '<BkgDetails>';
    $this->_dhlXMLBkgElement .=        '<PaymentCountryCode>'.MODULE_SHIPPING_DHL_FROM_CNTRY.'</PaymentCountryCode>';
    $this->_dhlXMLBkgElement .=        '<Date>'.zen_dhl_shipdate().'</Date>';
    $this->_dhlXMLBkgElement .=        '<ReadyTime>PT9H</ReadyTime>';
    $this->_dhlXMLBkgElement .=        '<DimensionUnit>'.MODULE_SHIPPING_DHL_DIM_UNIT.'</DimensionUnit>';
    $this->_dhlXMLBkgElement .=        '<WeightUnit>'.MODULE_SHIPPING_DHL_WEIGTH_UNIT.'</WeightUnit>';
    $this->_dhlXMLBkgElement .=             $this->_dhlXMLPiecesElement;
    $this->_dhlXMLBkgElement .=        '<PaymentAccountNumber>'.MODULE_SHIPPING_DHL_ACCOUNTNUMBER.'</PaymentAccountNumber>';
    $this->_dhlXMLBkgElement .=        '<IsDutiable>'.MODULE_SHIPPING_DHL_DUTIABLE.'</IsDutiable>';/*
    $this->_dhlXMLBkgElement .=        '<QtdShp>
                                        <GlobalProductCode>P</GlobalProductCode>
                                        <LocalProductCode>P</LocalProductCode>
                                            <QtdShpExChrg>
                                               <SpecialServiceType>DD</SpecialServiceType>
                                            </QtdShpExChrg>
                                         </QtdShp>';*/
    $this->_dhlXMLBkgElement .=  '</BkgDetails>';
  }
  
  function _dhlPiecesElement(){
      global $shipping_num_boxes,$shipping_weight;
    $this->_dhlXMLPiecesElement =            '<Pieces>';
    $box_counted = 0;
        while($box_counted != $shipping_num_boxes){
            $box_counted++;
            $this->_dhlXMLPiecesElement .=                '<Piece>';
            $this->_dhlXMLPiecesElement .=                     '<PieceID>'.$box_counted.'</PieceID>';
            $this->_dhlXMLPiecesElement .=                     '<Weight>'.$shipping_weight.'</Weight>';
            $this->_dhlXMLPiecesElement .=                '</Piece>';
        }
    $this->_dhlXMLPiecesElement .=            '</Pieces>';
  }
  
  
  function _dhlDutiableElement(){
      if(MODULE_SHIPPING_DHL_DUTIABLE == "Y"){
      $this->_dhlXMLDutiableElement = '<Dutiable>';
        $this->_dhlXMLDutiableElement .= '<DeclaredCurrency>'.MODULE_SHIPPING_DHL_CURRENCY.'</DeclaredCurrency>';
        $this->_dhlXMLDutiableElement .= '<DeclaredValue>'.$_SESSION['cart']->total.'</DeclaredValue>';
      $this->_dhlXMLDutiableElement .= '</Dutiable>';
      }
      else{
          $this->_dhlXMLDutiableElement = "";
      }
  }
  
  
  function _dhlGetQuote() {
         $xml = '<?xml version="1.0" encoding="UTF-8"?>';;
         $xml .=       '<req:DCTRequest xmlns:req="http://www.dhl.com">';
         $xml .=        '<GetQuote>';
         $xml .=            $this->_dhlXMLReqElement;
         $xml .=            $this->_dhlXMLFromElement;
         $xml .=            $this->_dhlXMLBkgElement;
         $xml .=            $this->_dhlXMLToElement;
         $xml .=            $this->_dhlXMLDutiableElement;
         $xml .=        '</GetQuote>';
         $xml .=       '</req:DCTRequest>';
         
            $tuCurl = curl_init();
            //echo 'Request:<textarea rows="8" cols="50">';
            //echo $xml;
            //echo '</textarea>';
            if(MODULE_SHIPPING_DHL_SERVER == "Production"){$url = "https://xmlpi-ea.dhl.com/XMLShippingServlet";}
            else{ $url = "https://xmlpitest-ea.dhl.com/XMLShippingServlet"; }
            //echo $url.'<br/>';
            curl_setopt($tuCurl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($tuCurl, CURLOPT_URL, $url);
            curl_setopt($tuCurl, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($tuCurl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($tuCurl, CURLOPT_PORT , 443);
             curl_setopt($tuCurl, CURLOPT_POSTFIELDS, $xml);
            $tuData = curl_exec($tuCurl);
            curl_close($tuCurl);
            $resp = $tuData;
            //echo 'Response:<textarea rows="8" cols="50">';
            //print_r($resp);
            //echo '</textarea>';
    $returnval =  simplexml_load_string($resp);

    return $returnval;
  }
}
  function zen_dhl_shipdate() {
        if (version_compare(PHP_VERSION, 5.2, '>=')) {
              if(date('l') == 'Saturday' || date('l') == 'Friday'){
                  $datetime = new DateTime('Monday next week');
              }
              else{
                  $datetime = new DateTime('tomorrow');
              }
          $dhl_shipdate = $datetime->format('Y-m-d');
        } else {
          $dhl_shipdate = date('Y-m-d');
        }
        return $dhl_shipdate;
  }