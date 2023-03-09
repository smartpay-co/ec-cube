<?php

/*
 * This file is part of Smartpay
 *
 * Copyright(c) Smartpay Solutions PTE. LTD. All Rights Reserved.
 *
 * https://smartpay.co
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
use Plugin\Smartpay\Client;
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

    /**
     * @var Client
     */
    private $client;

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
    ) {
        $this->cartService = $cartService;
        $this->orderHelper = $orderHelper;
        $this->eccubeConfig = $eccubeConfig;
        $this->orderStatusRepository = $orderStatusRepository;
        $this->orderRepository = $orderRepository;
        $this->mailService = $mailService;
        $this->paymentStatusRepository = $paymentStatusRepository;
        $this->config = $configRepository->get();
        $this->parameterBag = $parameterBag;
        $this->client = new Client(null, null, $this->config->getAPIPrefix());
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
        $successUrl = getenv('SMARTPAY_SUCCESS_URL');
        $cancelUrl = getenv('SMARTPAY_CANCEL_URL');

        if (!$successUrl || !$cancelUrl) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $successUrl = "{$protocol}{$_SERVER['HTTP_HOST']}/shopping/smartpay/payment/complete/{$Order->getId()}";
            $cancelUrl = "{$protocol}{$_SERVER['HTTP_HOST']}/shopping/smartpay/payment/cancel/{$Order->getId()}";
        }

        // Build request body
        $transformItems = function ($item) {
            if ($item->isDiscount()) {
                return [
                    'kind' => 'discount',
                    'name' => 'Discount',
                    'amount' => -1 * $item->getPriceIncTax(),
                    'currency' => $item->getCurrencyCode(),
                ];
            } else if ($item->isPoint()) {
                return [
                    'kind' => 'discount',
                    'name' => 'Point',
                    'amount' => -1 * $item->getPriceIncTax(),
                    'currency' => $item->getCurrencyCode(),
                ];
            } else if ($item->isTax()) {
                return [
                    'kind' => 'tax',
                    'name' => 'Tax',
                    'amount' => $item->getPriceIncTax(),
                    'currency' => $item->getCurrencyCode(),
                ];
            } else if ($item->isDeliveryFee()) {
                return null;
            } else if ($item->isProduct() || $item->isCharge()) {
                $description = "{$item->getClassCategoryName1()}{$item->getClassCategoryName2()}";
                return [
                    'name' => $item->getProductName() ?: 'Item',
                    'amount' => $item->getPriceIncTax(),
                    'currency' => $item->getCurrencyCode(),
                    'quantity' => $item->getQuantity(),
                ] + (empty($description) ? [] : [
                    'productDescription' => $description
                ]);
            } else {
                log_error("Unhandled item type: {$item->getOrderItemType()}");
                return null;
            }
        };
        try {
            $orderItems = $Order->getOrderItems()->getValues();
            // Sort by \Eccube\Entity\Master\OrderItemType so Product appears before of Charge
            usort($orderItems, function ($a, $b) {
                return ($a->getOrderItemTypeId() <=> $b->getOrderItemTypeId());
            });
            $lineItems = array_values(array_filter(array_map($transformItems, $orderItems)));
            $data = [
                'customerInfo' => [
                    "emailAddress" => $Order->getEmail(),
                    "firstName" => $Order->getName02(),
                    "lastName" => $Order->getName01(),
                    "firstNameKana" => $Order->getKana02(),
                    "lastNameKana" => $Order->getKana01(),
                    "phoneNumber" => preg_replace("/^0/", "+81", $Order->getPhoneNumber()),
                    "address" => [
                        "postalCode" => $Order->getPostalCode(),
                        "country" => "JP",
                        "line1" => "",
                        "locality" => "",
                    ],
                ],
                'amount' => $Order->getPaymentTotal(),
                'currency' => $Order->getCurrencyCode(),
                'items' => $lineItems,
                'shippingInfo' => [
                    'address' =>  [
                        'line1' => $Order->getAddr01(),
                        'line2' => $Order->getAddr02(),
                        'locality' => 'locality',
                        'postalCode' => $Order->getPostalCode(),
                        'country' => 'JP'
                    ],
                ],
                'reference' => "{$Order->getId()}",
                'successUrl' => $successUrl,
                'cancelUrl' => $cancelUrl,
            ];

            if ($Order->getDeliveryFeeTotal() > 0) {
                $data['shippingInfo']['feeAmount'] = $Order->getDeliveryFeeTotal();
                $data['shippingInfo']['feeCurrency'] = $Order->getCurrencyCode();
            }



            $checkoutSession = $this->client->httpPost("/checkout-sessions", $data);
            $sessionID = $checkoutSession['id'];
            $Order->setSmartpayPaymentCheckoutID($sessionID);
            $this->entityManager->flush();
            return $this->redirect($checkoutSession['url']);
        } catch (\Exception $e) {
            log_error('create checkoutSession error',
                [ 'stacktrace' => $e->getTraceAsString(), 'msg' => $e->getMessage()]
            );
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
                'SmartpayPaymentStatus' => PaymentStatus::ENABLED
            ]);

            if (null === $Order) {
                log_error("Order {$id} with smartpay_payment_status = 2 not found");
                $this->addError('受注情報が存在しません');
                return $this->redirectToRoute('shopping_error');
            }

            // Check if the payment is actually authorized
            $checkoutSessionID = $Order->getSmartpayPaymentCheckoutID();
            $checkoutSession = $this->client->httpGet("/checkout-sessions/{$checkoutSessionID}?expand=all");
            if ($checkoutSession['order']['status'] != 'succeeded') {
                log_error("Order {$id} found, but Smartpay order status is {$checkoutSession['order']['status']}");
                $this->addError('受注情報が存在しません');
                return $this->redirectToRoute('shopping_error');
            }

            $OrderStatus = $this->orderStatusRepository->find(OrderStatus::NEW);
            $Order->setOrderStatus($OrderStatus);
            $PaymentStatus = $this->paymentStatusRepository->find(PaymentStatus::ACTUAL_SALES);
            $Order->setSmartpayPaymentStatus($PaymentStatus);

            $this->purchaseFlow->commit($Order, new PurchaseContext());
            $this->completeShopping($Order);
            $PaidOrderStatus = $this->orderStatusRepository->find(OrderStatus::PAID);
            $this->orderRepository->changeStatus($Order->getId(), $PaidOrderStatus);

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
