<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Encryption;
use RuntimeException;

final class PaymentGatewayService
{
    public const PROVIDERS=['razorpay'=>'Razorpay Payment Links','cashfree'=>'Cashfree Payment Links','payu'=>'PayU Hosted Checkout'];

    public function configurations():array
    {
        $rows=Database::get()->all('SELECT id,provider,display_name,environment,public_credential,is_enabled,is_active,secret_credential_encrypted,webhook_secret_encrypted,updated_at FROM payment_gateway_configs ORDER BY provider');
        $by=[];foreach($rows as $row){$row['has_secret']=!empty($row['secret_credential_encrypted']);$row['has_webhook_secret']=!empty($row['webhook_secret_encrypted']);unset($row['secret_credential_encrypted'],$row['webhook_secret_encrypted']);$by[$row['provider']]=$row;}
        return $by;
    }

    public function save(string $provider,string $environment,string $publicCredential,?string $secret,?string $webhookSecret,bool $enabled,bool $active,int $actorId):void
    {
        if(!isset(self::PROVIDERS[$provider]))throw new RuntimeException('Unsupported payment gateway.');
        if(!in_array($environment,['sandbox','live'],true))throw new RuntimeException('Select sandbox or live mode.');
        $db=Database::get();$existing=$db->fetch('SELECT * FROM payment_gateway_configs WHERE provider=:provider',['provider'=>$provider]);
        $publicCredential=trim($publicCredential);$secret=trim((string)$secret);$webhookSecret=trim((string)$webhookSecret);
        $hasSecret=$secret!==''||!empty($existing['secret_credential_encrypted']);
        if($enabled&&($publicCredential===''||!$hasSecret))throw new RuntimeException('Public credential and secret are required before enabling a gateway.');
        if($active&&!$enabled)throw new RuntimeException('Enable the gateway before making it active.');
        $data=['display_name'=>self::PROVIDERS[$provider],'environment'=>$environment,'public_credential'=>$publicCredential,'is_enabled'=>$enabled?1:0,'is_active'=>$active?1:0,'updated_by'=>$actorId,'updated_at'=>date('Y-m-d H:i:s')];
        if($secret!=='')$data['secret_credential_encrypted']=Encryption::encrypt($secret);
        if($webhookSecret!=='')$data['webhook_secret_encrypted']=Encryption::encrypt($webhookSecret);
        $db->transaction(function(Database $db)use($provider,$existing,$data,$active):void{
            if($active)$db->query('UPDATE payment_gateway_configs SET is_active=0,updated_at=NOW() WHERE provider<>:provider',['provider'=>$provider]);
            if($existing)$db->update('payment_gateway_configs',$data,'id=:id',['id'=>$existing['id']]);
            else $db->insert('payment_gateway_configs',$data+['provider'=>$provider,'secret_credential_encrypted'=>null,'webhook_secret_encrypted'=>null,'configuration_json'=>null,'created_at'=>date('Y-m-d H:i:s')]);
        });
        AuditService::log('payment_gateway_configuration_updated','payment_gateway_config',$existing['id']??null,[],['provider'=>$provider,'environment'=>$environment,'enabled'=>$enabled,'active'=>$active,'secret_replaced'=>$secret!=='','webhook_secret_replaced'=>$webhookSecret!=='']);
    }

    /** @return array{kind:string,url?:string,action?:string,fields?:array<string,string>} */
    public function createCheckout(int $applicationId,int $userId):array
    {
        $db=Database::get();
        $application=$db->fetch('SELECT a.*,CONCAT(u.first_name,' ',u.last_name) AS name,u.email,u.mobile FROM applications a JOIN users u ON u.id=a.user_id WHERE a.id=:id AND a.user_id=:user',['id'=>$applicationId,'user'=>$userId]);
        if(!$application)throw new RuntimeException('Application not found.');
        $type=in_array($application['status'],['selected','payment_pending'],true)?'admission_fee':'application_fee';
        $allowed=$type==='admission_fee'?['selected','payment_pending']:['submitted','resubmitted','eligibility_check','under_review','correction_required'];
        if(!in_array($application['status'],$allowed,true))throw new RuntimeException('No online payment is due at the current application stage.');
        $assessment=$db->fetch('SELECT * FROM application_fee_assessments WHERE application_id=:application AND fee_type=:type',['application'=>$applicationId,'type'=>$type]);
        if(!$assessment||(float)$assessment['total_amount']<=0||in_array($assessment['status'],['paid','waived'],true))throw new RuntimeException('No positive unpaid fee assessment is available.');
        if($db->fetch("SELECT id FROM payments WHERE fee_assessment_id=:assessment AND status IN ('pending','verified') LIMIT 1",['assessment'=>$assessment['id']]))throw new RuntimeException('A payment for this fee is already pending or verified.');
        $config=$db->fetch("SELECT * FROM payment_gateway_configs WHERE is_enabled=1 AND is_active=1 ORDER BY id LIMIT 1");
        if(!$config)throw new RuntimeException('Online payment is not configured. Use the manual bank/UPI proof fallback.');
        $secret=(string)Encryption::decrypt($config['secret_credential_encrypted']);
        if($secret===''||trim((string)$config['public_credential'])==='')throw new RuntimeException('The active gateway configuration is incomplete.');
        $existing=$db->fetch("SELECT * FROM payment_gateway_transactions WHERE application_id=:application AND fee_assessment_id=:assessment AND status='created' AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY id DESC LIMIT 1",['application'=>$applicationId,'assessment'=>$assessment['id']]);
        if($existing){$response=json_decode((string)$existing['response_json'],true)?:[];if(!empty($response['redirect_url']))return ['kind'=>'redirect','url'=>$response['redirect_url']];}
        $reference='NCP'.date('ymd').str_pad((string)$applicationId,6,'0',STR_PAD_LEFT).bin2hex(random_bytes(3));
        $expires=time()+1800;$callback=url('payments/gateway/'.$config['provider'].'/return/'.$reference);
        $amount=(float)$assessment['total_amount'];$provider=(string)$config['provider'];$providerOrder='';$response=[];$checkout=[];
        if($provider==='razorpay'){
            $payload=['amount'=>(int)round($amount*100),'currency'=>$assessment['currency'],'accept_partial'=>false,'expire_by'=>$expires,'reference_id'=>$reference,'description'=>ucwords(str_replace('_',' ',$type)).' · '.$application['application_number'],'customer'=>['name'=>$application['name'],'contact'=>$application['mobile'],'email'=>$application['email']],'notify'=>['sms'=>false,'email'=>false],'reminder_enable'=>false,'callback_url'=>$callback,'callback_method'=>'get'];
            $api=$this->request('POST','https://api.razorpay.com/v1/payment_links',$payload,['Authorization: Basic '.base64_encode($config['public_credential'].':'.$secret)]);
            $providerOrder=(string)($api['id']??'');$url=(string)($api['short_url']??'');if($providerOrder===''||!str_starts_with($url,'https://'))throw new RuntimeException('Razorpay did not return a valid payment link.');
            $response=['redirect_url'=>$url];$checkout=['kind'=>'redirect','url'=>$url];
        }elseif($provider==='cashfree'){
            $base=$config['environment']==='live'?'https://api.cashfree.com/pg':'https://sandbox.cashfree.com/pg';
            $payload=['link_id'=>$reference,'link_amount'=>$amount,'link_currency'=>$assessment['currency'],'link_purpose'=>ucwords(str_replace('_',' ',$type)).' · '.$application['application_number'],'link_partial_payments'=>false,'customer_details'=>['customer_name'=>$application['name'],'customer_phone'=>$application['mobile']?:'9999999999','customer_email'=>$application['email']],'link_expiry_time'=>date(DATE_ATOM,$expires),'link_notify'=>['send_sms'=>false,'send_email'=>false],'link_meta'=>['return_url'=>$callback,'notify_url'=>url('payments/gateway/cashfree/webhook')]];
            $api=$this->request('POST',$base.'/links',$payload,['x-api-version: 2025-01-01','x-client-id: '.$config['public_credential'],'x-client-secret: '.$secret]);
            $providerOrder=(string)($api['link_id']??'');$url=(string)($api['link_url']??'');if($providerOrder===''||!str_starts_with($url,'https://'))throw new RuntimeException('Cashfree did not return a valid payment link.');
            $response=['redirect_url'=>$url];$checkout=['kind'=>'redirect','url'=>$url];
        }else{
            $providerOrder=$reference;$amountString=number_format($amount,2,'.','');$product=ucwords(str_replace('_',' ',$type));$firstname=trim(explode(' ',trim((string)$application['name']))[0]??'Applicant');
            $fields=['key'=>(string)$config['public_credential'],'txnid'=>$reference,'amount'=>$amountString,'productinfo'=>$product,'firstname'=>$firstname,'email'=>(string)$application['email'],'phone'=>(string)$application['mobile'],'surl'=>$callback,'furl'=>$callback,'udf1'=>(string)$applicationId,'udf2'=>'','udf3'=>'','udf4'=>'','udf5'=>''];
            $hashSequence=implode('|',[$fields['key'],$fields['txnid'],$fields['amount'],$fields['productinfo'],$fields['firstname'],$fields['email'],$fields['udf1'],$fields['udf2'],$fields['udf3'],$fields['udf4'],$fields['udf5'],'','','','','',$secret]);$fields['hash']=hash('sha512',$hashSequence);
            $action=$config['environment']==='live'?'https://secure.payu.in/_payment':'https://test.payu.in/_payment';$response=['payu_action'=>$action];$checkout=['kind'=>'form','action'=>$action,'fields'=>$fields];
        }
        $transactionId=$db->insert('payment_gateway_transactions',['application_id'=>$applicationId,'fee_assessment_id'=>$assessment['id'],'gateway_config_id'=>$config['id'],'provider_order_id'=>$providerOrder,'provider_payment_id'=>null,'amount'=>$amount,'currency'=>$assessment['currency'],'status'=>'created','request_reference'=>$reference,'payload_hash'=>hash('sha256',json_encode([$provider,$reference,$amount,$assessment['currency']],JSON_THROW_ON_ERROR)),'response_json'=>json_encode($response,JSON_UNESCAPED_SLASHES),'expires_at'=>date('Y-m-d H:i:s',$expires),'paid_at'=>null,'verified_at'=>null,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        AuditService::log('gateway_checkout_created','payment_gateway_transaction',$transactionId,[],['provider'=>$provider,'application_id'=>$applicationId,'assessment_id'=>$assessment['id'],'amount'=>$amount]);
        return $checkout;
    }

    public function completeReturn(string $provider,string $reference,array $data):bool
    {
        if(!isset(self::PROVIDERS[$provider]))throw new RuntimeException('Unsupported payment callback.');
        $db=Database::get();$transaction=$db->fetch("SELECT tx.*,config.provider,config.environment,config.public_credential,config.secret_credential_encrypted FROM payment_gateway_transactions tx JOIN payment_gateway_configs config ON config.id=tx.gateway_config_id WHERE tx.request_reference=:reference AND config.provider=:provider",['reference'=>$reference,'provider'=>$provider]);
        if(!$transaction)throw new RuntimeException('Payment transaction not found.');
        if($transaction['status']==='verified')return true;
        $secret=(string)Encryption::decrypt($transaction['secret_credential_encrypted']);$paymentId='';$verified=false;$providerEvidence=[];
        if($provider==='razorpay'){
            $link=(string)($data['razorpay_payment_link_id']??'');$paymentId=(string)($data['razorpay_payment_id']??'');$status=(string)($data['razorpay_payment_link_status']??'');$signature=(string)($data['razorpay_signature']??'');
            $payload=$link.'|'.(string)($data['razorpay_payment_link_reference_id']??'').'|'.$status.'|'.$paymentId;
            $verified=$link===$transaction['provider_order_id']&&(string)($data['razorpay_payment_link_reference_id']??'')===$reference&&$status==='paid'&&hash_equals(hash_hmac('sha256',$payload,$secret),$signature);
            $providerEvidence=['link_id'=>$link,'status'=>$status,'payment_id'=>$paymentId];
        }elseif($provider==='cashfree'){
            $base=$transaction['environment']==='live'?'https://api.cashfree.com/pg':'https://sandbox.cashfree.com/pg';
            $api=$this->request('GET',$base.'/links/'.rawurlencode($transaction['provider_order_id']),null,['x-api-version: 2025-01-01','x-client-id: '.$transaction['public_credential'],'x-client-secret: '.$secret]);
            $verified=strtoupper((string)($api['link_status']??''))==='PAID'&&abs((float)($api['link_amount_paid']??0)-(float)$transaction['amount'])<0.01&&(string)($api['link_id']??'')===$reference;
            $paymentId=(string)($api['cf_link_id']??$reference);$providerEvidence=['link_id'=>$api['link_id']??null,'status'=>$api['link_status']??null,'amount_paid'=>$api['link_amount_paid']??null];
        }else{
            $status=(string)($data['status']??'');$paymentId=(string)($data['mihpayid']??'');$received=(string)($data['hash']??'');$additional=(string)($data['additionalCharges']??'');
            $reverse=implode('|',[$secret,$status,'','','','','',(string)($data['udf5']??''),(string)($data['udf4']??''),(string)($data['udf3']??''),(string)($data['udf2']??''),(string)($data['udf1']??''),(string)($data['email']??''),(string)($data['firstname']??''),(string)($data['productinfo']??''),(string)($data['amount']??''),(string)($data['txnid']??''),(string)($data['key']??'')]);
            if($additional!=='')$reverse=$additional.'|'.$reverse;
            $verified=$status==='success'&&(string)($data['txnid']??'')===$reference&&abs((float)($data['amount']??0)-(float)$transaction['amount'])<0.01&&hash_equals(hash('sha512',$reverse),$received);
            $providerEvidence=['status'=>$status,'payment_id'=>$paymentId,'txnid'=>$data['txnid']??null];
        }
        $eventId=$db->insert('payment_gateway_events',['gateway_transaction_id'=>$transaction['id'],'gateway_config_id'=>$transaction['gateway_config_id'],'provider_event_id'=>null,'event_type'=>'browser_return','signature_valid'=>$verified?1:0,'payload_hash'=>hash('sha256',json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'processing_status'=>$verified?'verified':'rejected','error_message'=>$verified?null:'Gateway evidence or amount did not verify.','received_at'=>date('Y-m-d H:i:s'),'processed_at'=>date('Y-m-d H:i:s')]);
        if(!$verified){AuditService::log('gateway_return_rejected','payment_gateway_event',$eventId,[],['provider'=>$provider,'reference'=>$reference]);return false;}
        $this->recordVerifiedPayment((int)$transaction['id'],$paymentId,$providerEvidence);
        return true;
    }

    public function handleWebhook(string $provider,string $raw,array $headers):bool
    {
        if(!in_array($provider,['razorpay','cashfree'],true))throw new RuntimeException('Unsupported payment webhook.');
        $db=Database::get();$config=$db->fetch('SELECT * FROM payment_gateway_configs WHERE provider=:provider AND is_enabled=1',['provider'=>$provider]);
        if(!$config)throw new RuntimeException('Gateway webhook is not enabled.');
        $secret=(string)Encryption::decrypt($config['webhook_secret_encrypted']?:$config['secret_credential_encrypted']);
        if($secret==='')throw new RuntimeException('Gateway webhook secret is not configured.');
        $normalized=[];foreach($headers as $key=>$value)$normalized[strtolower((string)$key)]=(string)$value;
        $eventId=$provider==='razorpay'?($normalized['x-razorpay-event-id']??null):($normalized['x-idempotency-key']??null);
        if($eventId&&$db->fetch('SELECT id FROM payment_gateway_events WHERE gateway_config_id=:config AND provider_event_id=:event',['config'=>$config['id'],'event'=>$eventId]))return true;
        $signatureValid=$provider==='razorpay'
            ? hash_equals(hash_hmac('sha256',$raw,$secret),(string)($normalized['x-razorpay-signature']??''))
            : hash_equals(base64_encode(hash_hmac('sha256',(string)($normalized['x-webhook-timestamp']??'').$raw,$secret,true)),(string)($normalized['x-webhook-signature']??''));
        $payload=json_decode($raw,true);if(!is_array($payload))$payload=[];
        $transaction=null;$paymentId='';$paid=false;$eventType=(string)($payload['event']??$payload['type']??'unknown');$evidence=[];
        if($provider==='razorpay'){
            $link=$payload['payload']['payment_link']['entity']??[];$payment=$payload['payload']['payment']['entity']??[];
            $providerOrder=(string)($link['id']??'');$transaction=$providerOrder!==''?$db->fetch('SELECT * FROM payment_gateway_transactions WHERE gateway_config_id=:config AND provider_order_id=:order',['config'=>$config['id'],'order'=>$providerOrder]):null;
            $paymentId=(string)($payment['id']??'');$paid=$eventType==='payment_link.paid'&&(string)($link['status']??'')==='paid'&&$transaction&&abs(((float)($link['amount_paid']??0)/100)-(float)$transaction['amount'])<0.01;
            $evidence=['event'=>$eventType,'link_id'=>$providerOrder,'status'=>$link['status']??null,'payment_id'=>$paymentId];
        }else{
            $link=$payload['data']??$payload;$reference=(string)($link['link_id']??'');$transaction=$reference!==''?$db->fetch('SELECT * FROM payment_gateway_transactions WHERE gateway_config_id=:config AND request_reference=:reference',['config'=>$config['id'],'reference'=>$reference]):null;
            $paymentId=(string)($link['cf_link_id']??($link['order']['order_id']??''));$paid=in_array(strtoupper((string)($link['link_status']??'')),['PAID'],true)&&$transaction&&abs((float)($link['link_amount_paid']??0)-(float)$transaction['amount'])<0.01;
            $evidence=['event'=>$eventType,'link_id'=>$reference,'status'=>$link['link_status']??null,'payment_id'=>$paymentId];
        }
        $valid=$signatureValid&&$paid&&$transaction;
        $record=$db->insert('payment_gateway_events',['gateway_transaction_id'=>$transaction['id']??null,'gateway_config_id'=>$config['id'],'provider_event_id'=>$eventId,'event_type'=>$eventType,'signature_valid'=>$signatureValid?1:0,'payload_hash'=>hash('sha256',$raw),'processing_status'=>$valid?'verified':'rejected','error_message'=>$valid?null:'Webhook signature, transaction, status or amount did not verify.','received_at'=>date('Y-m-d H:i:s'),'processed_at'=>date('Y-m-d H:i:s')]);
        if(!$valid){AuditService::log('gateway_webhook_rejected','payment_gateway_event',$record,[],['provider'=>$provider,'event'=>$eventType]);return false;}
        $this->recordVerifiedPayment((int)$transaction['id'],$paymentId,$evidence);return true;
    }

    private function recordVerifiedPayment(int $transactionId,string $paymentId,array $evidence):void
    {
        $db=Database::get();$applicationId=$db->transaction(function(Database $db)use($transactionId,$paymentId,$evidence):int{
            $tx=$db->fetch('SELECT tx.*,config.provider FROM payment_gateway_transactions tx JOIN payment_gateway_configs config ON config.id=tx.gateway_config_id WHERE tx.id=:id FOR UPDATE',['id'=>$transactionId]);
            if(!$tx)throw new RuntimeException('Gateway transaction not found.');if($tx['status']==='verified')return (int)$tx['application_id'];
            $assessment=$db->fetch('SELECT * FROM application_fee_assessments WHERE id=:id FOR UPDATE',['id'=>$tx['fee_assessment_id']]);if(!$assessment||in_array($assessment['status'],['paid','waived'],true))throw new RuntimeException('Fee assessment is no longer payable.');
            if(abs((float)$assessment['total_amount']-(float)$tx['amount'])>0.01)throw new RuntimeException('Verified gateway amount does not match the fee assessment.');
            $existingPayment=$db->fetch("SELECT * FROM payments WHERE fee_assessment_id=:assessment AND status IN ('pending','verified') ORDER BY id DESC LIMIT 1 FOR UPDATE",['assessment'=>$assessment['id']]);
            if($existingPayment&&$existingPayment['status']==='verified')throw new RuntimeException('This fee already has a verified payment.');
            if($existingPayment)$db->update('payments',['status'=>'rejected','verification_remarks'=>'Superseded by a server-verified online gateway payment','verified_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$existingPayment['id']]);
            $receipt='NCP-RCT-'.date('Y').'-G'.str_pad((string)$tx['id'],6,'0',STR_PAD_LEFT);
            $db->insert('payments',['application_id'=>$tx['application_id'],'user_id'=>(int)$db->scalar('SELECT user_id FROM applications WHERE id=:id',['id'=>$tx['application_id']]),'fee_assessment_id'=>$assessment['id'],'type'=>$assessment['fee_type'],'amount'=>$tx['amount'],'currency'=>$tx['currency'],'method'=>'gateway_'.$tx['provider'],'reference_number'=>$paymentId?:$tx['request_reference'],'proof_path'=>null,'proof_original_name'=>null,'status'=>'verified','verification_remarks'=>'Server-verified online gateway payment','verified_by'=>null,'verified_at'=>date('Y-m-d H:i:s'),'receipt_number'=>$receipt,'paid_at'=>date('Y-m-d H:i:s'),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
            (new AdmissionFeeService())->markPaid($db,(int)$assessment['id']);
            $db->update('payment_gateway_transactions',['provider_payment_id'=>$paymentId?:null,'status'=>'verified','response_json'=>json_encode($evidence,JSON_UNESCAPED_SLASHES),'paid_at'=>date('Y-m-d H:i:s'),'verified_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$tx['id']]);
            if($assessment['fee_type']==='admission_fee'){
                $application=$db->fetch('SELECT * FROM applications WHERE id=:id FOR UPDATE',['id'=>$tx['application_id']]);
                if($application&&in_array($application['status'],['selected','payment_pending'],true)){$db->update('applications',['status'=>'fee_verified','status_version'=>(int)$application['status_version']+1,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$application['id']]);$db->insert('application_status_history',['application_id'=>$application['id'],'from_status'=>$application['status'],'to_status'=>'fee_verified','remarks'=>'Online gateway payment verified server-side','changed_by'=>null,'created_at'=>date('Y-m-d H:i:s')]);$db->query("UPDATE selection_offers SET status='payment_verified',payment_received_at=COALESCE(payment_received_at,NOW()),payment_verified_at=NOW(),updated_at=NOW() WHERE application_id=:application AND status IN ('payment_due','payment_received')",['application'=>$application['id']]);}
            }
            return (int)$tx['application_id'];
        });
        AuditService::log('gateway_payment_verified','payment_gateway_transaction',$transactionId,[],['application_id'=>$applicationId]);
        $notifier=new AdmissionNotificationService();$notifier->paymentReceived($applicationId);$notifier->status($applicationId,'fee_verified');
    }

    private function request(string $method,string $url,?array $payload,array $headers):array
    {
        if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL is required for online payments. Enable extension=curl in XAMPP.');
        $curl=curl_init($url);$headers[]='Accept: application/json';
        if($payload!==null)$headers[]='Content-Type: application/json';
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        if($payload!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);
        if($body===false||$error!=='')throw new RuntimeException('The payment gateway could not be reached securely.');
        $decoded=json_decode((string)$body,true);if($status<200||$status>=300||!is_array($decoded))throw new RuntimeException('The payment gateway rejected the server request. Review credentials, mode and gateway logs.');
        return $decoded;
    }
}
