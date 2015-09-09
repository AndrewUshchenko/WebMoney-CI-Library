<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class MerchantWM {
    private $wmPursesList = Array(
        "WMR"=>"R111111111111",
        "WMZ"=>"Z222222222222",
    );
    private $WM_WMSIGNER_PATH = '/domains/example.com/wm/'; 
    private $WM_SHOP_WMID = "1";
    private $LMI_SECRET_KEY = "123456";
    private $LMI_HASH_METHOD = "MD5";
    private $LMI_MODE = 1;
    private $WM_CACERT = './WebMoneyCA.crt';
    private $CI;
    public function __construct($config=Array()){
        if(!empty($config)){
            if(in_array($config["wmPursesList"]))
                $this->wmPursesList = $config["wmPursesList"];
            if(isset($config["WM_WMSIGNER_PATH"]))
                $this->WM_WMSIGNER_PATH = $config["WM_WMSIGNER_PATH"];
            if(isset($config["WM_SHOP_WMID"]))
                $this->WM_SHOP_WMID = $config["WM_SHOP_WMID"];
            if(isset($config["LMI_SECRET_KEY"]))
                $this->LMI_SECRET_KEY = $config["LMI_SECRET_KEY"];
            if(isset($config["LMI_HASH_METHOD"]))
                $this->LMI_HASH_METHOD = $config["LMI_HASH_METHOD"];
            if(isset($config["LMI_MODE"]))
                $this->LMI_MODE = $config["LMI_MODE"];
            if(isset($config["WM_CACERT"]))
                $this->WM_CACERT = $config["WM_CACERT"];
        }
        $this->CI = &get_instance();
        $this->CI->load->database();

        $sql = "SHOW TABLES LIKE 'payment'";
        $result = $this->CI->db->query($sql);
        $res = $result->result();
        if(empty($res)){
            $sql = "CREATE TABLE IF NOT EXISTS `payment` (
            `id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL DEFAULT '0',
            `state` enum('I','R','S','G','F') NOT NULL DEFAULT 'I',
            `timestamp` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            `price` float NOT NULL,
            `wm_type` char(3) NOT NULL
            ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;";
            $this->CI->db->query($sql);
            $sql = "ALTER TABLE `payment`
                ADD PRIMARY KEY (`id`);";
            $this->CI->db->query($sql);
            $sql = "ALTER TABLE `payment`
                MODIFY `id` int(11) NOT NULL AUTO_INCREMENT,AUTO_INCREMENT=1;";
            $this->CI->db->query($sql);
        }
    }
    public function payment($user_id,$price=0,$description='', $wmType="WMR"){
        $sql = "INSERT INTO payment (user_id,state,timestamp,price, wm_type)
            VALUES (".$this->CI->db->escape($user_id).", 'I',CURRENT_TIMESTAMP(), ".$this->CI->db->escape($price).", ".$this->CI->db->escape(strtoupper($wmType)).")";
        $this->CI->db->query($sql);
        $transaction_id = $this->CI->db->insert_id();
        if(empty($transaction_id)) return false;

        echo '<form id="pay" name="pay"" method="POST" accept-charset="windows-1251" action="https://merchant.webmoney.ru/lmi/payment.asp">'."\n";
        echo '<input type="hidden" name="LMI_PAYMENT_AMOUNT" value="'.$price.'">'."\n";
        echo '<input type="hidden" name="LMI_PAYMENT_DESC" value="'.$description.'">'."\n";
        echo '<input type="hidden" name="LMI_PAYMENT_NO" value="'.$transaction_id.'">'."\n";
        $purse = (!empty($this->wmPursesList[strtoupper($wmType)]))? $this->wmPursesList[strtoupper($wmType)] : array_pop($this->wmPursesList);
        if($this->LMI_MODE){
            echo '<input type="hidden" name="LMI_MODE" value="1">'."\n";
        }
        echo '<input type="hidden" name="LMI_PAYEE_PURSE" value="'.$purse.'">'."\n";
        echo '</form>'."\n";
        echo '<script>document.getElementById("pay").submit()</script>';
        return true;
    }
    public function failed(){
        if(!empty($_REQUEST['LMI_PAYMENT_NO'])){ # payment internal number
            $payment_id = (int) $_REQUEST["LMI_PAYMENT_NO"];
            $payment=$this->_findPayment($payment_id);
            if(empty($payment)) return false;
            $this->_setPaymentStatus($payment_id,'F');
            return true;
        }
        return false;
    }
    public function success(){
        if(!empty($_REQUEST['LMI_PAYMENT_NO'])){ # payment internal number
            $payment_id = (int) $_REQUEST["LMI_PAYMENT_NO"];
            $payment = $this->_findPayment($payment_id);
            if(empty($payment)) return false;
            if($payment[0]->state != 'S') return false;
            $this->_setPaymentStatus($payment_id,'G');
            return true;
        }
        return false;
    }
    public function result(){
        if(!empty($_REQUEST['LMI_PAYMENT_NO']) ){
            $payment_id = (int) $_REQUEST["LMI_PAYMENT_NO"];
            $payment = $this->_findPayment($payment_id);
            if(empty($payment)) return false;
            $chkstring =  $this->wmPursesList[$payment[0]->wm_type].$payment[0]->price.$payment_id.
            $_REQUEST['LMI_MODE'].$_REQUEST['LMI_SYS_INVS_NO'].$_REQUEST['LMI_SYS_TRANS_NO'].$_REQUEST['LMI_SYS_TRANS_DATE'].
            $this->LMI_SECRET_KEY.$_REQUEST['LMI_PAYER_PURSE'].$_REQUEST['LMI_PAYER_WM'];
            if($this->LMI_HASH_METHOD == "MD5"){
                $md5sum = strtoupper(md5($chkstring));
                $hash_check = ($_REQUEST['LMI_HASH'] == $md5sum);
            }
            elseif ( $LMI_HASH_METHOD == 'SIGN' ) {
                $PlanStr=$this->WM_SHOP_WMID.'967909998006'.$chkstring.$_REQUEST['LMI_HASH'];
                $SignStr=$this->_wm_GetSign($PlanStr);
                if( strlen($SignStr) < 132){
                    return false;
                }
                $req="/asp/classicauth.asp?WMID={$this->WM_SHOP_WMID}&CWMID=967909998006&CPS=".urlencode($chkstring).
                "&CSS=".$_REQUEST['LMI_HASH']."&SS=$SignStr";
                $resp=$this->_wm_HttpsReq($req);
                if($resp=='Yes'){
                    $hash_check = true ;
                } 
                else {
                    $hash_check = false;
                }
            } 
            else {
                return false;
            }	  
            if($_REQUEST['LMI_PAYMENT_NO'] == $payment_id
                && $_REQUEST['LMI_PAYEE_PURSE'] == $this->wmPursesList[$payment[0]->wm_type] 
                && $_REQUEST['LMI_PAYMENT_AMOUNT'] == $payment[0]->price
                && $_POST['LMI_MODE'] == $this->LMI_MODE
                && $hash_check) { 
                $this->_setPaymentStatus($payment_id,"S");
                return true;
            }
            else {
                return false;
            }
            return true;
        }
        return false;
    }
    private function _setPaymentStatus($id,$status){
        $sql = "UPDATE payment SET state='".$status."', timestamp=CURRENT_TIMESTAMP() WHERE id='".$id."';";
        $this->CI->db->query($sql);
    }
    private function _findPayment($payment_id=0){
        $sql = "SELECT * FROM payment WHERE id=".$payment_id;
        $result = $this->CI->db->query($sql);
        if(empty($result)) return false;
        return $result->result();
    }
    private function _wm_HttpsReq($addr)
    { 
        $ch = curl_init("https://w3s.webmoney.ru".$addr);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_CAINFO, $this->WM_CACERT);
        $result=curl_exec($ch);
        if( curl_errno($ch) != 0 ) {
            return false;
        };
        curl_close($ch);
        return $result;
    }
    private function _wm_GetSign($inStr)
    { 
        $descriptorspec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "r") );
        $process = proc_open($this->WM_WMSIGNER_PATH, $descriptorspec, $pipes );
        if (is_resource($process)) {
            fwrite($pipes[0], "$inStr\004\r\n");
            fclose($pipes[0]);
            $s = fgets($pipes[1], 133);
            fclose($pipes[1]);
            $return_value = proc_close($process);
            return $s;
        }
    }
}