<?php

/*
 * This file is part of Smartpay
 *
 * Copyright(c) Smartpay Solutions PTE. LTD. All Rights Reserved.
 *
 * https://homepage.smartpay.ninja/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\Smartpay\Controller;


use Eccube\Common\EccubeConfig;
use Eccube\Controller\AbstractShoppingController;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Repository\OrderRepository;
use Eccube\Service\CartService;
use Eccube\Service\MailService;
use Eccube\Service\OrderHelper;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Plugin\Smartpay\Entity\Config;
use Plugin\Smartpay\Entity\PaymentStatus;
use Plugin\Smartpay\Repository\ConfigRepository;
use Plugin\Smartpay\Repository\PaymentStatusRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Class SmartpayController
 * @package Plugin\Smartpay\Controller
 *
 * @Route("/shopping/smartpay")
 */
class PaymentController extends AbstractShoppingController
{
    /**
     * @var CartService
     */
    private $cartService;

    /**
     * @var OrderHelper
     */
    private $orderHelper;

    /**
     * @var OrderStatusRepository
     */
    private $orderStatusRepository;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var MailService
     */
    private $mailService;

    /**
     * @var PaymentStatusRepository
     */
    private $paymentStatusRepository;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ParameterBag
     */
    private $parameterBag;

    public function __construct(
        CartService $cartService,
        OrderHelper $orderHelper,
        EccubeConfig $eccubeConfig,
        OrderStatusRepository $orderStatusRepository,
        OrderRepository $orderRepository,
        MailService $mailService,
        PaymentStatusRepository $paymentStatusRepository,
        ConfigRepository $configRepository,
        ParameterBag $parameterBag
    )
    {
        $this->cartService = $cartService;
        $this->orderHelper = $orderHelper;
        $this->eccubeConfig = $eccubeConfig;
        $this->orderStatusRepository = $orderStatusRepository;
        $this->orderRepository = $orderRepository;
        $this->mailService = $mailService;
        $this->paymentStatusRepository = $paymentStatusRepository;
        $this->config = $configRepository->get();
        $this->parameterBag = $parameterBag;
    }

    /**
     * @return RedirectResponse
     * @throws \Smartpay\Exception\ApiErrorException
     *
     * @Route("/payment", name="shopping_smartpay_payment")
    */
    public function payment(): RedirectResponse
    {
        // 受注情報の取得
        /** @var Order $Order 受注情報の取得 */
        $Order = $this->parameterBag->get('smartpay.Order');

        if (!$Order) {
            return $this->redirectToRoute('shopping_error');
        }

        // Build redirect URL params
        $successURL = getenv('SMARTPAY_SUCCESS_URL');
        $cancelURL = getenv('SMARTPAY_CANCEL_URL');

        if (!$successURL || !$cancelURL) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $successURL = "{$protocol}{$_SERVER['HTTP_HOST']}/shopping/smartpay/payment/complete/{$Order->getId()}";
            $cancelURL = "{$protocol}{$_SERVER['HTTP_HOST']}/shopping/smartpay/payment/cancel/{$Order->getId()}"; 
        }

        // Build request body
        $transformProductItems = function($product)
        {
            $description = "{$product->getClassCategoryName1()}{$product->getClassCategoryName2()}";
            return [
                'priceData' => [
                    'productData' => [
                        'name' => $product->getProductName(),
                    ] + (empty($description) ? [] : [
                        'description' => $description
                    ]),
                    'amount' => $product->getTotalPrice(),
                    'currency' => $product->getCurrencyCode(),
                ],
                'quantity' => $product->getQuantity(),
            ];
        };


        try {
            $publicKey = getenv('SMARTPAY_PUBLIC_KEY');
            $secretKey = getenv('SMARTPAY_SECRET_KEY');
            $url = "{$this->config->getAPIPrefix()}/checkout/sessions";
            $lineItems = array_map($transformProductItems, $Order->getProductOrderItems());

            if ($Order->getDeliveryFeeTotal() > 0) {
                $lineItems[count($lineItems)] = [
                    'priceData' => [
                        'productData' => [
                            'name' => '配送手数料',
                        ],
                        'amount' => $Order->getDeliveryFeeTotal(),
                        'currency' => $Order->getCurrencyCode(),
                    ],
                    'quantity' => 1,
                ];
            }

            $data = [
                'successUrl' => $successURL,
                'cancelUrl' => $cancelURL,
                'customerInfo' => [
                    "emailAddress" => $Order->getEmail(),
                ],
                'orderData' => [
                    'amount' => $Order->getTotalPrice(),
                    'currency' => $Order->getCurrencyCode(),
                    'lineItemData' => $lineItems,
                    'shippingInfo' => [
                        'address' =>  [
                            'line1' => $Order->getAddr01(),
                            'line2' => $Order->getAddr02(),
                            'locality' => 'locality',
                            'postalCode' => $Order->getPostalCode(),
                            'country' => 'JP'
                        ],
                    ],
                ],
            ];

            function httpPost($url, $data){
                $secretKey = getenv('SMARTPAY_SECRET_KEY');
                $curl = curl_init($url);
                $authorization = "Authorization: Basic {$secretKey}";
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                    'Accept: application/json',
                    'Content-Type: application/json' ,
                    $authorization
                ));
                curl_setopt($curl, CURLOPT_ENCODING, 'gzip,deflate,sdch');
                $response = curl_exec($curl);
                curl_close($curl);
                return json_decode($response, true);
            }

            $checkoutSession = httpPost($url, $data);
            $sessionID = $checkoutSession['id'];
            $Order->setSmartpayPaymentCheckoutID($sessionID);

            header("Location: {$this->config->getCheckoutURL()}/login?session-id={$sessionID}");
            exit;
        } catch (\Exception $e) {
            $this->addError($e->getMessage());
            return $this->redirectToRoute('shopping_error');
        }
    }

    /**
     * @return RedirectResponse
     * @throws \Smartpay\Exception\ApiErrorException
     *
     * @Route("/payment/complete/{id}", name="shopping_smartpay_payment_complete")
     */
    public function paymentComplete(string $id): RedirectResponse
    {
        try {
            $Order = $this->orderRepository->findOneBy([
                'id' => $id,
            ]);

            if (null === $Order) {
                $this->addError('受注情報が存在しません');
                return $this->redirectToRoute('shopping_error');
            }

            $OrderStatus = $this->orderStatusRepository->find(OrderStatus::NEW);
            $Order->setOrderStatus($OrderStatus);

            $PaymentStatus = $this->paymentStatusRepository->find(PaymentStatus::PROVISIONAL_SALES);
            $Order->setSmartpayPaymentStatus($PaymentStatus);

            $this->purchaseFlow->commit($Order, new PurchaseContext());
            $this->completeShopping($Order);

            return $this->redirectToRoute('shopping_complete');
        } catch (\Exception $e) {
            $this->addError($e->getMessage());
            return $this->redirectToRoute('shopping_error');
        }
    }

    /**
     * @return RedirectResponse
     * @throws \Smartpay\Exception\ApiErrorException
     *
     * @Route("/payment/cancel/{id}", name="shopping_smartpay_payment_cancel")
     */
    public function paymentCancel(string $id): RedirectResponse
    {
        try {
            $Order = $this->orderRepository->findOneBy([
                'id' => $id,
            ]);

            if (null === $Order) {
                $this->addError('受注情報が存在しません');
                return $this->redirectToRoute('shopping_error');
            }
            
            $OrderStatus = $this->orderStatusRepository->find(OrderStatus::PROCESSING);
            $Order->setOrderStatus($OrderStatus);

            $this->addError('Smartpay決済がキャンセルされました');
            $this->cancelShopping($Order);

            return $this->redirectToRoute('shopping_error');
        } catch (\Exception $e) {
            $this->addError($e->getMessage());
            return $this->redirectToRoute('shopping_error');
        }
    }

    /**
     * @param Order $Order
     */
    protected function completeShopping(Order $Order)
    {
        $this->mailService->sendOrderMail($Order);
        $this->cartService->clear();
        $this->session->set(OrderHelper::SESSION_ORDER_ID, $Order->getId());
        $this->entityManager->flush();
    }

    /**
     * @param Order $Order
     */
    protected function cancelShopping(Order $Order)
    {
        $this->purchaseFlow->rollback($Order, new PurchaseContext());
        $this->entityManager->flush();
    }
}
