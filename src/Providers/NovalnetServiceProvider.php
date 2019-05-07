<?php
/**
 * This module is used for real time processing of
 * Novalnet payment module of customers.
 * Released under the GNU General Public License.
 * This free contribution made by request.
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet AG
 * @copyright(C) Novalnet AG
 * All rights reserved. https://www.novalnet.de/payment-plugins/kostenpflichtig/lizenz
 */

namespace Novalnet\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Basket\Events\Basket\AfterBasketCreate;
use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodContainer;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Log\Loggable;
use Novalnet\Helper\PaymentHelper;
use Novalnet\Services\PaymentService;
use Novalnet\Services\TransactionService;
use Plenty\Plugin\Templates\Twig;
use Plenty\Plugin\ConfigRepository;
use IO\Services\SessionStorageService;
use IO\Constants\SessionStorageKeys;
use Plenty\Modules\Order\Pdf\Events\OrderPdfGenerationEvent;
use Plenty\Modules\Order\Pdf\Models\OrderPdfGeneration;

use Novalnet\Methods\NovalnetInvoicePaymentMethod;
use Novalnet\Methods\NovalnetPrepaymentPaymentMethod;
use Novalnet\Methods\NovalnetCcPaymentMethod;
use Novalnet\Methods\NovalnetSepaPaymentMethod;
use Novalnet\Methods\NovalnetSofortPaymentMethod;
use Novalnet\Methods\NovalnetPaypalPaymentMethod;
use Novalnet\Methods\NovalnetIdealPaymentMethod;
use Novalnet\Methods\NovalnetEpsPaymentMethod;
use Novalnet\Methods\NovalnetGiropayPaymentMethod;
use Novalnet\Methods\NovalnetPrzelewyPaymentMethod;
use Novalnet\Methods\NovalnetCashPaymentMethod;

use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;

/**
 * Class NovalnetServiceProvider
 *
 * @package Novalnet\Providers
 */
class NovalnetServiceProvider extends ServiceProvider
{
    use Loggable;

    /**
     * Register the route service provider
     */
    public function register()
    {
        $this->getApplication()->register(NovalnetRouteServiceProvider::class);
    }

    /**
     * Boot additional services for the payment method
     *
     * @param Dispatcher $eventDispatcher
     * @param paymentHelper $paymentHelper
     * @param PaymentService $paymentService
     * @param BasketRepositoryContract $basketRepository
     * @param PaymentMethodContainer $payContainer
     * @param PaymentMethodRepositoryContract $paymentMethodService
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param TransactionService $transactionLogData
     * @param Twig $twig
     * @param ConfigRepository $config
     */
    public function boot( Dispatcher $eventDispatcher,
                          PaymentHelper $paymentHelper,
						  AddressRepositoryContract $addressRepository,
                          PaymentService $paymentService,
                          BasketRepositoryContract $basketRepository,
                          PaymentMethodContainer $payContainer,
                          PaymentMethodRepositoryContract $paymentMethodService,
                          FrontendSessionStorageFactoryContract $sessionStorage,
                          TransactionService $transactionLogData,
                          Twig $twig,
                          ConfigRepository $config,
                          EventProceduresService $eventProceduresService)
    {

	   
	    
        // Register the Novalnet payment methods in the payment method container
        $payContainer->register('plenty_novalnet::NOVALNET_INVOICE', NovalnetInvoicePaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_PREPAYMENT', NovalnetPrepaymentPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_CC', NovalnetCcPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_SEPA', NovalnetSepaPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_SOFORT', NovalnetSofortPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_PAYPAL', NovalnetPaypalPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_IDEAL', NovalnetIdealPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_EPS', NovalnetEpsPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_GIROPAY', NovalnetGiropayPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_PRZELEWY', NovalnetPrzelewyPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        $payContainer->register('plenty_novalnet::NOVALNET_CASHPAYMENT', NovalnetCashPaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
            
		// Event for Onhold - Capture Process
		$captureProcedureTitle = [
            'de' => 'Novalnet | Bestätigen',
            'en' => 'Novalnet | Confirm',
        ];
        $eventProceduresService->registerProcedure(
            'Novalnet',
            ProcedureEntry::EVENT_TYPE_ORDER,
            $captureProcedureTitle,
            '\Novalnet\Procedures\CaptureEventProcedure@run'
        );
        
        // Event for Onhold - Void Process
        $voidProcedureTitle = [
            'de' => 'Novalnet | Stornieren',
            'en' => 'Novalnet | Cancel',
        ];
        $eventProceduresService->registerProcedure(
            'Novalnet',
            ProcedureEntry::EVENT_TYPE_ORDER,
            $voidProcedureTitle,
            '\Novalnet\Procedures\VoidEventProcedure@run'
        );
        
        // Event for Onhold - Refund Process
        $refundProcedureTitle = [
            'de' =>  'Novalnet | Rückerstattung',
            'en' =>  'Novalnet | Refund',
        ];
        $eventProceduresService->registerProcedure(
            'Novalnet',
            ProcedureEntry::EVENT_TYPE_ORDER,
            $refundProcedureTitle,
            '\Novalnet\Procedures\RefundEventProcedure@run'
        );
        
        // Listen for the event that gets the payment method content
        $eventDispatcher->listen(GetPaymentMethodContent::class,
                function(GetPaymentMethodContent $event) use($config, $paymentHelper, $addressRepository, $paymentService, $basketRepository, $paymentMethodService, $sessionStorage, $twig)
                {
	 	$sessionStorageValue = pluginApp(SessionStorageService::class);
		$customerWish = $sessionStorageValue->getSessionValue(SessionStorageKeys::ORDER_CONTACT_WISH);
		$sessionStorageValue->setSessionValue(SessionStorageKeys::ORDER_CONTACT_WISH, null);
                    if($paymentHelper->getPaymentKeyByMop($event->getMop()))
                    {	
			    
						$paymentKey = $paymentHelper->getPaymentKeyByMop($event->getMop());	
						$guaranteeStatus = $paymentService->getGuaranteeStatus($basketRepository->load(), $paymentKey);
						$basket = $basketRepository->load();			
						$billingAddressId = $basket->customerInvoiceAddressId;
						$address = $addressRepository->findAddressById($billingAddressId);
			    			foreach ($address->options as $option) {
							if ($option->typeId == 12) {
							    $name = $option->value;
							}
						}
						$customerName = explode(' ', $name);
						$firstname = $customerName[0];
						if( count( $customerName ) > 1 ) {
						    unset($customerName[0]);
						    $lastname = implode(' ', $customerName);
						} else {
						    $lastname = $firstname;
						}
						$firstName = empty ($firstname) ? $lastname : $firstname;
						$lastName = empty ($lastname) ? $firstname : $lastname;
			    			$endCustomerName = $firstName .' '. $lastName;
			    			$endUserName = $address->firstName .' '. $address->lastName;

						$name = trim($config->get('Novalnet.' . strtolower($paymentKey) . '_payment_name'));
						$paymentName = ($name ? $name : $paymentHelper->getTranslatedText(strtolower($paymentKey)));
						$redirect = $paymentService->isRedirectPayment($paymentKey);	
							
						if ($redirect && $paymentKey != 'NOVALNET_CC') { # Redirection payments
							$serverRequestData = $paymentService->getRequestParameters($basketRepository->load(), $paymentKey);
                            $sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData['data']);
                            $sessionStorage->getPlugin()->setValue('nnPaymentUrl', $serverRequestData['url']);
                            $content = '';
                            $contentType = 'continue';
						} elseif ($paymentKey == 'NOVALNET_CC') { # Credit Card
                            $encodedKey = base64_encode('vendor='.$paymentHelper->getNovalnetConfig('novalnet_vendor_id').'&product='.$paymentHelper->getNovalnetConfig('novalnet_product_id').'&server_ip='.$paymentHelper->getServerAddress().'&lang='.$sessionStorage->getLocaleSettings()->language);
                            $nnIframeSource = 'https://secure.novalnet.de/cc?api=' . $encodedKey;
                            $content = $twig->render('Novalnet::PaymentForm.NOVALNET_CC', [
								'nnCcFormUrl' 			=> $nnIframeSource,
								'nnPaymentProcessUrl' 	=> $paymentService->getProcessPaymentUrl(),
								'paymentMopKey'     	=>  $paymentKey,
				    			'paymentName' => $paymentName,
								'nnFormDesign'  		=>  $paymentService->getCcDesignConfig()
                                       ]);
                            $contentType = 'htmlContent';
						} elseif($paymentKey == 'NOVALNET_SEPA') {
                                $paymentProcessUrl = $paymentService->getProcessPaymentUrl();
								
                                $contentType = 'htmlContent';
                                $guaranteeStatus = $paymentService->getGuaranteeStatus($basketRepository->load(), $paymentKey);

                                if($guaranteeStatus != 'normal' && $guaranteeStatus != 'guarantee')
                                {
                                    $contentType = 'errorCode';
                                    $content = $guaranteeStatus;
                                }
                                else
                                {
				
			
									$content = $twig->render('Novalnet::PaymentForm.NOVALNET_SEPA', [
                                                                    'nnPaymentProcessUrl' => $paymentProcessUrl,
                                                                    'paymentMopKey'     =>  $paymentKey,
									'paymentName' => $paymentName,	
										
								'endcustomername'=> empty(trim($endUserName)) ? $endCustomerName : $endUserName,
                                                                    'nnGuaranteeStatus' =>  empty($address->companyName) ? $guaranteeStatus : ''
                                                 ]);
                                }
                            } else {
								if(in_array($paymentKey, ['NOVALNET_INVOICE', 'NOVALNET_PREPAYMENT', 'NOVALNET_CASHPAYMENT']))
								{
									$processDirect = true;
									$B2B_customer   = false;
									if($paymentKey == 'NOVALNET_INVOICE')
									{
										$guaranteeStatus = $paymentService->getGuaranteeStatus($basketRepository->load(), $paymentKey);
										if($guaranteeStatus != 'normal' && $guaranteeStatus != 'guarantee')
										{
											$processDirect = false;
											$contentType = 'errorCode';
											$content = $guaranteeStatus;
										}
										else if($guaranteeStatus == 'guarantee')
										{
											$processDirect = false;
											
											$paymentProcessUrl = $paymentService->getProcessPaymentUrl();
											if (empty($address->companyName)) {
											$content = $twig->render('Novalnet::PaymentForm.NOVALNET_INVOICE', [
																'nnPaymentProcessUrl' => $paymentProcessUrl,
												'paymentName' => $paymentName,				
												'paymentMopKey'     =>  $paymentKey
											
											]); 												$contentType = 'htmlContent';
											} else {
												$processDirect = true;												
												$B2B_customer  = true;
											}
										 }
									}
									if ($processDirect) {
									$content = '';
									$contentType = 'continue';
									$serverRequestData = $paymentService->getRequestParameters($basketRepository->load(), $paymentKey);
										if( $B2B_customer) {
											$serverRequestData['data']['payment_type'] = 'GUARANTEED_INVOICE';
											$serverRequestData['data']['key'] = '41';
										}
									$sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData['data']);
									$response = $paymentHelper->executeCurl($serverRequestData['data'], $serverRequestData['url']);
									$responseData = $paymentHelper->convertStringToArray($response['response'], '&');	
									$sessionStorage->getPlugin()->setValue('pdfGeneration', $responseData);
									if ($responseData['status'] == '100') {
										$notificationMessage = $paymentHelper->getNovalnetStatusText($responseData); 
									        $paymentService->pushNotification($notificationMessage, 'success', 100); 
									}
									if ($responseData['status']!= '100') {
										$contentType = 'errorCode';
										$content = $paymentHelper->getNovalnetStatusText($responseData); 
									}
									$responseData['payment_id'] = (!empty($responseData['payment_id'])) ? $responseData['payment_id'] : $responseData['key'];
									$sessionStorage->getPlugin()->setValue('nnPaymentData', array_merge($serverRequestData['data'], $responseData));
									
									
									} 
								} 
							}
			    $sessionStorage->getPlugin()->setValue('customerWish', $customerWish);
								$event->setValue($content);
								$event->setType($contentType);
						} 
                });

	    
	    
        // Listen for the event that executes the payment
        $eventDispatcher->listen(ExecutePayment::class,
            function (ExecutePayment $event) use ($paymentHelper, $paymentService, $sessionStorage, $transactionLogData,$config,$basketRepository)
            {
                if($paymentHelper->getPaymentKeyByMop($event->getMop())) {
                    $sessionStorage->getPlugin()->setValue('nnOrderNo',$event->getOrderId());
                    $sessionStorage->getPlugin()->setValue('mop',$event->getMop());
                    $paymentKey = $paymentHelper->getPaymentKeyByMop($event->getMop());
                    $sessionStorage->getPlugin()->setValue('paymentkey', $paymentKey);

                    if(!$paymentService->isRedirectPayment($paymentKey)) {
                        $paymentService->validateResponse();
                    } else {
                        $paymentProcessUrl = $paymentService->getRedirectPaymentUrl();
                        $event->setType('redirectUrl');
                        $event->setValue($paymentProcessUrl);
                    }
                }
            }
        );
	   
	// Invoice PDF Generation
	
	// Listen for the document generation event
	    $eventDispatcher->listen(OrderPdfGenerationEvent::class,
	    function (OrderPdfGenerationEvent $event) use ($paymentHelper) {
		/** @var Order $order */
		$order = $event->getOrder();
                
		  $orderPdfGenerationModel = pluginApp(OrderPdfGeneration::class);
		    
		  $invoicePrepaymentDetails =  [
			  'invoice_bankname'  => 'test',
			  'invoice_bankplace' => 'test',
			  'amount'            => 100,
			  'currency'          => 'EUR',
			  'tid'               => '486903489690386',
			  'invoice_iban'      => '4835903485093485943',
			  'invoice_bic'       => '9503946346964',
			  'due_date'          => 'rtretre',
			  'product'           => '14',
			  'order_no'          => '4332',
			  'tid_status'        => '100',
			  'invoice_type'      => 'INVOICE',
			  'test_mode'	      => '1',
			  'invoice_account_holder' => 'NovalnetAg'
		  ];
		  $transactionDetails = $paymentService->getInvoicePrepaymentComments($invoicePrepaymentDetails);
		    $orderPdfGenerationModel->advice = implode($transactionDetails);
		   $event->addOrderPdfGeneration($orderPdfGenerationModel); 

	    }
	);  
	 
    }
	
	
	 

	
	
        
    
}
