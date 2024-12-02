<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Citizen\Controllers\CompanyController;
use Modules\Citizen\Controllers\StoredPaymentController;
use Modules\Citizen\Controllers\UtilityAccountController;
use Modules\CorrespondenceController;
use Modules\Helpers\CommonHelper;
use Modules\Payment\Controllers\ProcessorConfigController;
use Modules\Payment\Controllers\StoreOrderController;
use App\Library\Pay\Handlers\PaymentAmountProcessor;

class PaymentController extends Controller
{
    
    protected $authorization = Config::get('HeartLand.heartland_dev_auth');

    protected $certStr = Config::get('HeartLand.heartland_dev_cert');

    protected $proPayPaymentURL = Config::get('HeartLand.heartland_dev_protect_payurl');

    protected $receivingAccountId = Config::get('HeartLand.heartland_dev_dtc');

    protected $proPayURL = Config::get('HeartLand.heartland_dev_propay_payurl');

    protected $termId = Config::get('HeartLand.heartland_dev_termid');





    
   public function makePayment(request $request){

    $billedAmount = (float)$request->bill;
    $feeAmount = (float)$request->fee;

    $processedAmounts = $this->processAmounts($billedAmount, $feeAmount,discountAmount: 0.00, absorbFee: false);

    Log::info('Processed amounts: ' . json_encode($processedAmounts));
   }







/******************************************************************************************************* */
/*                             ACTUALLY PROCESS THE PAYMENT 
/*
/******************************************************************************************************* */




private function ProtectPaySplitPay($payerId, $payMethodId, $billedAmount, $feeAmount, $invoiceNumber)
{
    Log::channel($this->loggerName)->info(
        "Protect Pay Split Payment",
        [
            "PayerId" => $payerId . "\n",
            "PayMethodId" => $payMethodId . "\n",
            "BilledAmount" => $billedAmount . "\n",
            "FeeAmount" => $feeAmount . "\n",
            "InvoiceNumber" => $invoiceNumber . "\n",
        ]
    );

    $this->setProtectPayPaymentURL($payerId, 1);
    //convert Amounts  to pennies
    $payAmount = (round($billedAmount * 100)) + (round($feeAmount * 100));
    $feeAmount = (round($feeAmount * 100));

    $payAmount = (int) $payAmount;
    $feeAmount = (int) $feeAmount;

    Log::channel($this->loggerName)->info(
        "Protect Pay Split Payment",
        [
            "Amounts" => [
                "PayAmount" => $payAmount . "\n",
                "FeeAmount" => $feeAmount . "\n",
                "URL" => $this->proPayPaymentURL,
            ],
        ]
    );
    $requestSentToProcessor = [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => '[REDACTED]', // Don't store actual auth token
        ],
        'url' => $this->proPayPaymentURL,
        'payload' => [
            "PaymentMethodID" => $payMethodId,
            "SecondaryTransaction" => [
                "OriginatingAccountId" => $this->OriginatingAccountId,
                "ReceivingAccountId" => $this->receivingAccountId,
                "Amount" => $feeAmount,
            ],
            "CreditCardOverrides" => null,
            "AchOverrides" => [
                "SecCode" => "WEB",
            ],
            "PayerOverrides" => [
                "IpAddress" => "127.0.0.1",
            ],
            "MerchantProfileId" => $this->protectPayId,
            "PayerAccountId" => $payerId,
            "Amount" => $payAmount,
            "CurrencyCode" => "USD",
            "AccountCountryCode" => "USA",
            "Invoice" => $invoiceNumber,
            "Comment1" => "Transaction created on" . Carbon::now()->toDateTimeString() . " UTC TIME for " . $invoiceNumber,
            "Comment2" => "ProcessSplitPayTransaction Comment 2",
            "IsDebtRepayment" => "false",
        ],
        'timestamp' => Carbon::now()->toDateTimeString(),
    ];

    $response = http::withHeaders([
        'Content-Type' => 'application/json',
        'Authorization' => $this->authorization,
    ])->put($this->proPayPaymentURL, $requestSentToProcessor['payload']);

    $this->requestSent = $requestSentToProcessor;
    // $response = http::withHeaders([
    //     'Content-Type' => 'application/json',
    //     'Authorization' => $this->authorization,
    // ])->put($this->proPayPaymentURL, [
    //     "PaymentMethodID" => $payMethodId,
    //     "SecondaryTransaction" => [
    //         "OriginatingAccountId" => $this->OriginatingAccountId,
    //         "ReceivingAccountId" => $this->receivingAccountId,
    //         "Amount" => $feeAmount, //amounts in pennies
    //     ],
    //     "CreditCardOverrides" => null,
    //     "AchOverrides" => [
    //         //"BankAccountType" => "checking",
    //         "SecCode" => "WEB",
    //     ],
    //     "PayerOverrides" => [
    //         "IpAddress" => "127.0.0.1",
    //     ],
    //     "MerchantProfileId" => $this->protectPayId, //3100446
    //     "PayerAccountId" => $payerId,
    //     "Amount" => $payAmount, //amounts in pennies
    //     "CurrencyCode" => "USD",
    //     "AccountCountryCode" => "USA",
    //     "Invoice" => $invoiceNumber,
    //     "Comment1" => "Transaction created on" . Carbon::now()->toDateTimeString() . " UTC TIME for " . $invoiceNumber,
    //     "Comment2" => "ProcessSplitPayTransaction Comment 2",
    //     "IsDebtRepayment" => "false",
    // ]);

    Log::channel($this->loggerName)->info("Protect Pay Split Payment", ["Response" => $response->json()]);
    return $response->json();
}

// this is making the NON SPLIT (CLIENTS WHO ABSORB) payment through protect pay (Hosted Payment Page)
private function ProtectPayPay($payerId, $payMethodId, $billedAmount, $invoiceNumber)
{
    $this->setProtectPayPaymentURL($payerId, 0);
    //convert Amounts  to pennies
    $payAmount = (round($billedAmount * 100));
    $payAmount = (int) $payAmount;

    Log::channel($this->loggerName)->info(
        "NO SPLIT Protect Pay Payment \n",
        [
            "URL" => $this->proPayPaymentURL . "\n",
            "BIlledAMT Comming into to function " => $billedAmount . "\n",
            "PayAmount to Propay" => $payAmount . "\n",
        ]
    );
    $requestSentToProcessor = [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => '[REDACTED]', // Don't store actual auth token for security
        ],
        'url' => $this->proPayPaymentURL,
        'payload' => [
            "PaymentMethodID" => $payMethodId,
            "IsRecurringPayment" => false,
            "CreditCardOverrides" => null,
            "AchOverrides" => [
                "SecCode" => "WEB",
                "PayerOverrides" => [
                    "IpAddress" => "127.0.0.1",
                ],
            ],
            "MerchantProfileId" => $this->protectPayId,
            "PayerAccountId" => $payerId,
            "Amount" => $payAmount,
            "CurrencyCode" => "USD",
            "Invoice" => $invoiceNumber,
            "Comment1" => "Transaction created on" . Carbon::now()->toDateTimeString() . " UTC TIME for " . $invoiceNumber,
            "Comment2" => "Credit Comment 2",
            "IsDebtRepayment" => "false",
            "IsQuasiCash" => "false",
        ],
        'timestamp' => Carbon::now()->toDateTimeString(),
    ];

    $response = http::withHeaders([
        'Content-Type' => 'application/json',
        'Authorization' => $this->authorization,
    ])->put($this->proPayPaymentURL, $requestSentToProcessor['payload']);

    $this->requestSent = $requestSentToProcessor;
    // $response = http::withHeaders([
    //     'Content-Type' => 'application/json',
    //     'Authorization' => $this->authorization,
    // ])->put($this->proPayPaymentURL, [
    //     "PaymentMethodID" => $payMethodId,
    //     "IsRecurringPayment" => false,
    //     "CreditCardOverrides" => null,
    //     "AchOverrides" => [
    //         //"BankAccountType" => "Checking", //available Checking,Savings,LoanCredit,GeneralLedger
    //         "SecCode" => "WEB", //available PPD,CCD,WEB,TEL
    //         "PayerOverrides" => [
    //             "IpAddress" => "127.0.0.1",
    //         ],
    //     ],
    //     "MerchantProfileId" => $this->protectPayId, //merchant it is going to //3100446
    //     "PayerAccountId" => $payerId,
    //     "Amount" => $payAmount, //amounts in pennies
    //     "CurrencyCode" => "USD",
    //     "Invoice" => $invoiceNumber,
    //     "Comment1" => "Transaction created on" . Carbon::now()->toDateTimeString() . " UTC TIME for " . $invoiceNumber,
    //     "Comment2" => "Credit Comment 2",
    //     "IsDebtRepayment" => "false",
    //     "IsQuasiCash" => "false",

    // ]);
    Log::channel($this->loggerName)->info("Protect Pay Payment", ["Response" => $response->json()]);
    return $response->json();
}

// this is making the split payment through propay (Used for Swipe and IVR)
private function propaySplitPay($payMethodId, $billedAmount, $feeAmount, $invoiceNumber)
{

    Log::channel('ivrLoggerv2')->info("Propay Split", ["PayMethodId" => $payMethodId, "BilledAmount" => $billedAmount, "FeeAmount" => $feeAmount, "InvoiceNumber" => $invoiceNumber]);

    $this->setProPayUrl();
    Log::channel('ivrLoggerv2')->info("Propay Split", ["PayMethodId" => $payMethodId, "BilledAmount" => $billedAmount, "FeeAmount" => $feeAmount, "InvoiceNumber" => $invoiceNumber]);

    if (!$this->isIvr) {
        $cardNum = $this->swipeDetails['card_no'];
        $expDate = $this->swipeDetails['expiry_month'] . substr(strval($this->swipeDetails['expiry_year']), -2);
        $name = $this->swipeDetails['name_on_card'];
        $cvv = $this->swipeDetails['cvv'];
        Log::channel('ivrLoggerv2')->info("In PROPAY SPLIT");

    } else {

        $cardNum = $this->cardDetails['cardNumber'];
        $expDate = $this->cardDetails['expiryDate'];
        $name = $this->cardDetails['name_on_card'];
        $cvv = $this->cardDetails['cvv'];
    };

    //*****************************************************
    // revised code BH 11-18-24 ***************************
    // OLD CODE was:
    //   $totalToBePaid = intval(value: $billedAmount * 100) ;
    //   $feeAmount = intval(value: $feeAmount * 100);

    $totalToBePaid = intval(round($billedAmount * 100));
    $feeAmount = intval(round($feeAmount * 100));

    $totalToBePaid = intval($totalToBePaid + $feeAmount);
    //******************************************************

    $replacementArr = array("$", "&", "%", "?", "'", '"');
    $xml =
    '
    <XMLRequest>
    <certStr>' . $this->certStr . '</certStr>
    <termid>' . $this->termId . '</termid>
    <class>partner</class>
    <XMLTrans>
    <transType>33</transType>
    <accountNum>' . $this->OriginatingAccountId . '</accountNum>
    <recAccntNum>' . $this->receivingAccountId . '</recAccntNum>
    <amount>' . $totalToBePaid . '</amount>
    <ccNum>' . $cardNum . '</ccNum>
    <expDate>' . $expDate . '</expDate>
    <cardholderName>' . str_replace($replacementArr, "", $name) . '</cardholderName>
    <invNum>' . $invoiceNumber . '</invNum>
    <secondaryAmount>' . $feeAmount . '</secondaryAmount>
    </XMLTrans>
    </XMLRequest>
    ';

    $requestSentToProcessor = $xml;
    $this->requestSent = $requestSentToProcessor;
    $response = $this->runCurlRequest($xml);
    if ($this->isIvr) {
        Log::channel('ivrLoggerv2')->info("Propay Split Response", ["Response" => $response]);
    }
    return $response;
}

// this is making the NON SPLIT (v) payment through propay (Used for Swipe and IVR)
private function propayPay($payMethodId, $billedAmount, $feeAmount, $invoiceNumber)
{

    // revised BH 11-18-24 ******************************
    //    $totalToBePaid = intval( $billedAmount * 100);
    $totalToBePaid = intval(round($billedAmount * 100));

    Log::channel($this->loggerName)->info(
        "Propay Pay NO SPILT",
        [
            "PayMethodId" => $payMethodId . "\n",
            "BilledAmount" => $billedAmount . "\n",
            "FeeAmount" => $feeAmount . "\n",
            "InvoiceNumber" => $invoiceNumber . "\n",
            "*TotalToBePaid*" => $totalToBePaid . "\n",
        ]
    );
    if (!$this->isIvr) {
        $cardNum = $this->swipeDetails['card_no'];
        $expDate = $this->swipeDetails['expiry_month'] . substr(strval($this->swipeDetails['expiry_year']), -2);
        $name = $this->swipeDetails['name_on_card'];
        $cvv = $this->swipeDetails['cvv'];
        Log::channel('ivrLoggerv2')->info("In propay NON Split ");

    } else {

        $cardNum = $this->cardDetails['cardNumber'];
        $expDate = $this->cardDetails['expiryDate'];
        $name = $this->cardDetails['name_on_card'];
        $cvv = $this->cardDetails['cvv'];
    }

    $replacementArr = array("$", "&", "%", "?", "'", '"');
    $xml = '
    <!DOCTYPE Request.dtd>
    <XMLRequest>
    <certStr>' . $this->certStr . '</certStr>
    <termid>' . $this->termId . '</termid>
    <class>partner</class>
    <XMLTrans>
    <transType>04</transType>
    <amount>' . $totalToBePaid . '</amount>
    <addr>right here</addr>
    <zip>22222</zip>
    <accountNum>' . $this->OriginatingAccountId . '</accountNum>
    <ccNum>' . $cardNum . '</ccNum>
    <expDate>' . $expDate . '</expDate>
    <CVV2>' . $cvv . '</CVV2>
    <cardholderName>' . str_replace($replacementArr, "", $name) . '</cardholderName>
    <invNum>' . $invoiceNumber . '</invNum>
    <billPay>N</billPay>
    <DebtRepayment>N</DebtRepayment>
    <isquasicash>N</isquasicash>
    </XMLTrans>
    </XMLRequest>
   ';

    $requestSentToProcessor = $xml;
    $this->requestSent = $requestSentToProcessor;

    $response = $this->runCurlRequest($xml);
    if ($this->isIvr) {
        Log::channel('ivrLoggerv2')->info("Propay Pay Response", ["Response" => $response]);
    }
    // Log::channel($this->loggerName)->info("Propay Pay Response",["Response"=>$response]);
    return $response;
}

// this is used for the propay transactions
private function runCurlRequest($xml)
{
    if (app()->environment('production')) {
        $this->proPayURL = "https://epay.propay.com/api/propayapi.aspx";
    } else {
        $this->proPayURL = "https://xmltest.propay.com/API/PropayAPI.aspx";
    }
    //dd($this->proPayURL);
    //  dd($this->authorization);
    //Initiate cURL
    $curl = curl_init($this->proPayURL);
    //Set the Content-Type to text/xml.
    curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-Type: text/xml"));
    curl_setopt($curl, CURLOPT_HTTPHEADER, array("Authorization:" . $this->authorization));
    //Set CURLOPT_POST to true to send a POST request.
    curl_setopt($curl, CURLOPT_POST, true);
    //Attach the XML string to the body of our request.
    curl_setopt($curl, CURLOPT_POSTFIELDS, $xml);
    //Tell cURL that we want the response to be returned as
    //a string instead of being dumped to the output.
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    //Execute the POST request and send our XML.
    $result = curl_exec($curl);
    //Do some basic error checking.
    if (curl_errno($curl)) {
        if ($this->isIvr) {
            Log::channel('ivrLoggerv2')->info("CURLERROR", ["ERR" => curl_error($curl)]);
        }
        Log::channel($this->loggerName)->info("CURLERROR", ["ERR" => curl_error($curl)]);

        throw new \Exception(curl_error($curl));

    }
    //Close the cURL handle.
    curl_close($curl);
    //Print out the response output.
    $responseArr = $result;

    if ($this->isIvr) {
        Log::channel('ivrLoggerv2')->info("CURL Response", ["Response" => $responseArr]);
    }
    return $responseArr;
}














































/************************************************************************************************************************************************************************************************* */
/*
/*                                                                                          PAYMENT AMOUNT PROCESSING CODE
/*
/***************************************************************** ******************************************************************************************************************************* */

    
    public  function processAmounts(float $billedAmount, float $feeAmount, float $discountAmount = 0.00, bool $absorbFee = false): array 
    {
        // Convert all amounts to pennies for exact math
        $billedPennies = $this->toPennies($billedAmount);
        $feePennies = $this->toPennies($feeAmount);
        $discountPennies = $this->toPennies($discountAmount);

        // Apply discount if any
        if ($discountPennies > 0) {
            $billedPennies = $billedPennies - $discountPennies;
        }

        // Handle fee absorption
        $actualFeePennies = $absorbFee ? 0 : $feePennies;

        // Calculate total
        $totalPennies = $billedPennies + $actualFeePennies;

        return [
            'billed' => [
                'pennies' => $billedPennies,
                'amount' => $this->fromPennies($billedPennies)
            ],
            'fee' => [
                'pennies' => $actualFeePennies,
                'amount' => $this->fromPennies($actualFeePennies)
            ],
            'discount' => [
                'pennies' => $discountPennies,
                'amount' => $this->fromPennies($discountPennies)
            ],
            'total' => [
                'pennies' => $totalPennies,
                'amount' => $this->fromPennies($totalPennies)
            ]
        ];
    }

    /**
     * Convert amount to pennies
     */
    private function toPennies($amount): int 
    {
        return (int)round($amount * 100);
    }

    /**
     * Convert pennies back to decimal amount
     */
    private function fromPennies(int $pennies): string 
    {
        return number_format($pennies / 100, 2, '.', '');
    }

    /**
     * Validate payment amounts and relationships
     */
    public function validateAmounts(array $amounts): bool 
    {
        // Check for negative amounts
        if ($amounts['billed']['pennies'] < 0 || 
            $amounts['fee']['pennies'] < 0 || 
            $amounts['total']['pennies'] < 0) {
            return false;
        }

        // Validate total matches billed + fee
        if ($amounts['total']['pennies'] !== 
            ($amounts['billed']['pennies'] + $amounts['fee']['pennies'])) {
            return false;
        }

        return true;
    }
}
   
    
    



