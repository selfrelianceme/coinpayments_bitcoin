<?php

namespace Selfreliance\CoinPayments;

use App\Models\MerchantPosts;
use Illuminate\Http\Request;

use Illuminate\Foundation\Validation\ValidatesRequests;

use Selfreliance\CoinPayments\Events\CoinPaymentsPaymentIncome;
use Selfreliance\CoinPayments\Events\CoinPaymentsPaymentCancel;

use Selfreliance\CoinPayments\CoinPaymentsInterface;

use Selfreliance\CoinPayments\Libs\CoinPaymentsAPI;
use Selfreliance\CoinPayments\Exceptions\CoinPaymentsException;

class CoinPayments implements CoinPaymentsInterface {
    use ValidatesRequests;
    public $memo;
    private $cps;
    private $unit = 'LTCT';

    function __construct(){
        $this->cps = new CoinPaymentsAPI();
        $this->cps->Setup(config('coinpayments.private_key'),config('coinpayments.public_key'));
    }

    /**
     * @param bool $unit
     * @return mixed
     * @throws \Exception
     */
    public function balance($unit = false){
        if(!$unit) {
            $unit = $this->unit;
        }
        $unit = strtoupper($unit);
        $result = $this->cps->GetBalances(true);
        if($result['error'] != 'ok') {
            throw new \Exception($result['error']);
        }

        return $result['result'][$unit]['balancef'];
    }

    /**
     * @param $payment_id
     * @param $sum
     * @param $units
     * @return \stdClass
     * @throws \Exception
     */
    public function form($payment_id, $sum, $units){
        if(config('coinpayments.use_merchant') === true){
		    ob_start();
            echo '<form class="form_payment" id="FORM_pay_ok" action="https://www.coinpayments.net/index.php" method="POST">';
            $form_data = [
                'cmd'           => '_pay_simple',
                'reset'         => '1',
                'merchant'      => config('coinpayments.merchant_id'),
                'item_name'     => $this->memo,
                'invoice'       => $payment_id,
                'currency'      => $units,
                'amountf'       => $sum,
                'want_shipping' => 0,
                'success_url'   => '',
                'cancel_url'    => '',
                'ipn_url'       => route('coinpayments.confirm'),
            ];
            foreach($form_data as $key => $value) {
                echo '<input type="hidden" name="'.$key.'" value="'.$value.'">';
            }
            echo '<input type="submit" style="width:0;height:0;border:0px; background:none;" class="content__login-submit submit_pay_ok" name="PAYMENT_METHOD" value="">';
            echo '</form>';
            $content = ob_get_contents();
            ob_end_clean();
            return $content;
        }else{
            $req = [
                'amount'      => $sum,
                'currency1'   => $units,
                'currency2'   => $units,
                'item_name'   => 'Order '.$payment_id,
                'item_number' => $payment_id,
                'ipn_url'     => route('coinpayments.confirm')
            ];
            $result = $this->cps->CreateTransaction($req);

            if ($result['error'] != 'ok'){
                throw new \Exception($result['error']);
            }
            $PassData = new \stdClass();
            $PassData->address = $result['result']['address'];
            $PassData->another_site = false;
            if(array_key_exists('dest_tag', $result['result'])){
                $PassData->destination_tag = $result['result']['dest_tag'];
            }
            return $PassData;
        }
    }

    /**
     * @param $request
     */
    public function check_transaction($request){

    }

    /**
     * @param Request $request
     * @return Ipn
     */
    public function validateIPNRequest(Request $request){
        return $this->income_payment($request->all(),$request->server(),$request->headers);
    }

    /**
     * @param array      $request
     * @param array|null $server
     * @param array      $headers
     *
     * @return Ipn
     * @throws IpnIncompleteException|CoinPaymentsException
     */
    public function income_payment(array $request,array $server,$headers = []){
        MerchantPosts::create([
            'type'      => 'CoinPayments',
            'ip'        => real_ip(),
            'post_data' => $request
        ]);


        try {
            $is_complete = $this->validateIPN($request,$server);
            if($is_complete) {
                $PassData = new \stdClass();
                $PassData->amount = $request['received_amount'];
                $PassData->payment_id = $request['item_number'];
                $PassData->search_by_currency = true;
                $PassData->currency = $request['currency1'];
                $PassData->transaction = $request['txn_id'];
                $PassData->add_info = [
                    "ipn_id"        => $request['ipn_id'],
                    "full_data_ipn" => json_encode($request)
                ];
                event(new CoinPaymentsPaymentIncome($PassData));
            }
        } catch(CoinPaymentsException $e) {
            MerchantPosts::create([
                'type'      => 'CoinPayments Error',
                'ip'        => real_ip(),
                'post_data' => $e->getMessage()
            ]);
        }

    }

    /**
     * Validate the IPN request and payment.
     *
     * @param  array $post_data
     * @param  array $server_data
     *
     * @return mixed
     * @throws CoinPaymentsException
     */
    public function validateIPN(array $post_data,array $server_data){
        if(!isset($post_data['ipn_mode'],$post_data['merchant'],$post_data['status'],$post_data['status_text'])) {
            throw new CoinPaymentsException("Insufficient POST data provided.");
        }

        if($post_data['ipn_mode'] == 'httpauth') {
            if($server_data['PHP_AUTH_USER'] !== config('coinpayments.merchant_id')) {
                throw new CoinPaymentsException("Invalid merchant ID provided.");
            }
            if($server_data['PHP_AUTH_PW'] !== config('coinpayments.ipn_secret')) {
                throw new CoinPaymentsException("Invalid IPN secret provided.");
            }
        } elseif($post_data['ipn_mode'] == 'hmac') {
            $hmac = hash_hmac("sha512",file_get_contents('php://input'),config('coinpayments.ipn_secret'));
            if($hmac !== $server_data['HTTP_HMAC']) {
                throw new CoinPaymentsException("Invalid HMAC provided.");
            }
            if($post_data['merchant'] !== config('coinpayments.merchant_id')) {
                throw new CoinPaymentsException("Invalid merchant ID provided.");
            }
        } else {
            throw new CoinPaymentsException("Invalid IPN mode provided.");
        }

        $order_status = $post_data['status'];

        return ($order_status >= 100 || $order_status == 2);
    }

    /**
     * @param $payment_id
     * @param $amount
     * @param $address
     * @param $currency
     * @return \stdClass
     * @throws \Exception
     */
    public function send_money($payment_id, $amount, $address, $currency){
        $auto_confirm = true;
        $ipn_url = route('coinpayments.webhookwithdraw');
        $result = $this->cps->CreateWithdrawal($amount,$currency,$address,$auto_confirm,$ipn_url);
        if($result['error'] != 'ok') {
            throw new \Exception($result['error']);
        }

        $PassData = new \stdClass();
        $PassData->sending = true;
        $PassData->transaction = $result['result']['id'];
        $PassData->add_info = [
            "id"        => $result['result']['id'],
            'status'    => $result['result']['status'],
            'amount'    => $result['result']['amount'],
            "full_data" => $result
        ];

        return $PassData;
    }

    /**
     * @param Request $request
     */
    public function webhookwithdraw(Request $request){
        /**
         * Добавить больше проверок валидации вход данных
         */

//        Withdraw::id($request->input('id'))->currency($request->input('currency'))->txn_id($request->input('txn_id'))->transaction_compleated();


        MerchantPosts::create([
            'type'      => 'CoinPaymentsWebHookWithdraw',
            'ip'        => real_ip(),
            'post_data' => $request->all()
        ]);
    }

    /**
     * @param Request $request
     */
    public function cancel_payment(Request $request){

    }
}