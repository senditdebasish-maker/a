<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Flash;
use App\Services\PaymentGatewayService;
use RuntimeException;

final class PaymentGatewayController extends Controller
{
    public function saveConfiguration(string $provider): never
    {
        try{
            $values=(array)(($_POST['gateway']??[])[$provider]??[]);
            (new PaymentGatewayService())->save(
                $provider,(string)($values['environment']??'sandbox'),trim((string)($values['public_credential']??'')),
                (string)($values['secret_credential']??''),(string)($values['webhook_secret']??''),!empty($values['is_enabled']),!empty($values['is_active']),(int)Auth::id()
            );
            Flash::set('success','Payment gateway configuration saved. Secret fields remain write-only.');
        }catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}
        $this->redirect('admin/settings#payment');
    }

    public function start(): never
    {
        $application=Database::get()->fetch('SELECT id FROM applications WHERE user_id=:user ORDER BY created_at DESC LIMIT 1',['user'=>Auth::id()]);
        if(!$application){Flash::set('warning','No application is available for payment.');$this->redirect('student/payments');}
        try{
            $checkout=(new PaymentGatewayService())->createCheckout((int)$application['id'],(int)Auth::id());
            if($checkout['kind']==='redirect'){
                $url=(string)$checkout['url'];$host=strtolower((string)parse_url($url,PHP_URL_HOST));
                if(!str_ends_with($host,'.razorpay.com')&&!str_ends_with($host,'.cashfree.com')&&!str_ends_with($host,'.cashfreepayments.com')&&!str_ends_with($host,'.rzp.io')&&$host!=='rzp.io')throw new RuntimeException('Gateway returned an untrusted redirect host.');
                header('Location: '.$url,true,303);exit;
            }
            $this->view('student/gateway-redirect',['checkout'=>$checkout,'title'=>'Continue to secure payment'],'student');exit;
        }catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());$this->redirect('student/payments');}
    }

    public function razorpayReturn(string $reference): never { $this->finish('razorpay',$reference,$_GET); }
    public function cashfreeReturn(string $reference): never { $this->finish('cashfree',$reference,$_GET); }
    public function payuReturn(string $reference): never { $this->finish('payu',$reference,$_POST); }

    public function razorpayWebhook(): never { $this->webhook('razorpay'); }
    public function cashfreeWebhook(): never { $this->webhook('cashfree'); }

    private function webhook(string $provider):never
    {
        $raw=(string)file_get_contents('php://input');$headers=function_exists('getallheaders')?(array)getallheaders():[];
        if(!$headers)foreach($_SERVER as $key=>$value)if(str_starts_with($key,'HTTP_'))$headers[str_replace(' ','-',ucwords(strtolower(str_replace('_',' ',substr($key,5)))))]=$value;
        try{$ok=(new PaymentGatewayService())->handleWebhook($provider,$raw,$headers);$this->json(['received'=>$ok],$ok?200:422);}catch(RuntimeException){$this->json(['received'=>false],422);}
    }

    private function finish(string $provider,string $reference,array $data):never
    {
        try{$verified=(new PaymentGatewayService())->completeReturn($provider,$reference,$data);Flash::set($verified?'success':'warning',$verified?'Online payment verified successfully. Your receipt is available in the portal.':'Payment could not be verified. No fee was marked paid; contact Accounts before retrying.');}
        catch(RuntimeException $exception){Flash::set('warning',$exception->getMessage());}
        $this->redirect(Auth::check()?'student/payments':'login');
    }
}
