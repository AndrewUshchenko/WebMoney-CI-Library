# WebMoney-CI-Library
WebMoney(WM) merchant for php framework CodeIgniter
Libraries for CodeIgniter

WebMoney Merchant:
example:
```
 public function index()
 {
     $this->load->library('MerchantWM');
     $result = $this->merchantwm->payment(1,10,'add funds','wmz'}
 }
 ```

List methods:
```
bool payment($user_id,$price,[$description='',$typePurse='wmr']);
bool failed();//Set operation failed status
bool success();//Set operation success status
bool result();//Check operation
```
