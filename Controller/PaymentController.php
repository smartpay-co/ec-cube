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
use Plugin\Smartpay\Util\Base62;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\JsonResponse;

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
            $successUrl = $this->generateUrl('shopping_smartpay_payment_complete', ['id' => $Order->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
            $cancelUrl = $this->generateUrl('shopping_smartpay_payment_cancel', ['id' => $Order->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        }
        try {
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
            $this->cancelShopping($Order);
            $this->addError($e->getMessage());
            return $this->redirectToRoute('shopping_error');
        }
    }

    /**
     * @return JsonResponse
     * @throws \Smartpay\Exception\ApiErrorException
     *
     * @Route("/payment/webhook", name="shopping_smartpay_payment_webhook", methods={"POST"}))
     */
    public function paymentWebhook(Request $request): JsonResponse
    {
        // Check if webhook is setup
        $webhookId = getenv('SMARTPAY_WEBHOOK_ID'); 
        $signingSecret  = getenv('SMARTPAY_WEBHOOK_SECRET');
        if (empty($webhookId) || empty($signingSecret)) {
            log_info("[Smartpay Webhook] signing id or secret not found, skipping...");
            return new JsonResponse(['error' => 'Smartpay webhook is not setup yet.'], 200);
        }

        // Check if webhook request is valid
        $req_signature  = $request->headers->get('Smartpay-Signature');
        $req_timestamp  = $request->headers->get('Smartpay-Signature-Timestamp');
        $req_webhook_id = $request->headers->get('Smartpay-Subscription-Id');
        $req_event_id   = $request->headers->get('Smartpay-Event-Id');
        
        if (empty($req_signature) || empty($req_timestamp) || empty($req_webhook_id) || empty($req_event_id)) {
            log_info("[Smartpay Webhook] invalid headers, skipping...");
            return new JsonResponse(['error' => 'Smartpay webhook headers missing.'], 400);
        }
        log_info("[Smartpay Webhook] {$req_webhook_id} received event {$req_event_id}");

        if ($req_webhook_id !== $webhookId) {
            log_info("[Smartpay Webhook] webhook id mismatch, skipping...");
            return new JsonResponse(['error' => 'Smartpay webhook id mismatch.'], 200);
        }

        // validate request signature
        if (!$this->validateSignature($signingSecret, $req_signature, $req_timestamp, $request->getContent())) {
            log_info("[Smartpay Webhook] invalid signature, skipping...");
            return new JsonResponse(['error' => 'Invalid Smartpay webhook signature.'], 200);
        }
        log_info("[Smartpay Webhook] signature validated");

        $payload = json_decode($request->getContent(), true);
        $smartpayOrderId = $payload['eventData']['data']['id'];
        log_info("[Smartpay Webhook] smartpayOrderId", ['smartpayOrderId' => $smartpayOrderId]);
        
        // Handle order logic
        try {
            // Get Smartpay Order
            $smartpayOrder = $this->client->httpGet("/orders/{$smartpayOrderId}");

            if (array_key_exists('reference', $smartpayOrder) && !empty($smartpayOrder['reference'])) {
                $id = $smartpayOrder['reference'];
            } else {
                log_error("[Smartpay Webhook] Smartpay order reference not found, skipping...");
                return new JsonResponse(['error' => 'Smartpay order reference not found.'], 200);
            }

            // Check if the order is paid
            if ($smartpayOrder['status'] != 'succeeded') {
                log_error("[Smartpay Webhook] Smartpay order status is {$smartpayOrder['status']}, skipping...");
                return new JsonResponse(['error' => 'Smartpay order status is not correct.'], 200);
            }
            
            // Find the order in ECCUBE using reference field
            $Order = $this->orderRepository->findOneBy([
                'id' => $id
            ]);

            // Check if the order exists
            if (null === $Order) {
                log_error("[Smartpay Webhook] Order {$id} not found, skipping...");
                return new JsonResponse(['error' => 'ECCUBE order not found.'], 200);
            }

            // Check if the order waiting for payment
            if ($Order->getSmartpayPaymentStatus()->getId() != PaymentStatus::ENABLED) {
                log_error("[Smartpay Webhook] Order {$id} is not waiting for payment, skipping...");
                return new JsonResponse(['error' => 'ECCUBE order is not waiting for payment.'], 200);
            }

            // Double check if the reference order is correct
            $checkoutSessionID = $Order->getSmartpayPaymentCheckoutID();
            $checkoutSession = $this->client->httpGet("/checkout-sessions/{$checkoutSessionID}?expand=all");
            if ($checkoutSession['order']['id'] != $smartpayOrderId) {
                log_error("[Smartpay Webhook ]Order {$id} found, but smartpayOrderId mismatch {$checkoutSession['order']['id']} <=> {$smartpayOrderId}");
                return new JsonResponse(['error' => 'SmartpayOrderId mismatch.'], 200);
            }

            // Update order status
            $OrderStatus = $this->orderStatusRepository->find(OrderStatus::NEW);
            $Order->setOrderStatus($OrderStatus);
            $PaymentStatus = $this->paymentStatusRepository->find(PaymentStatus::ACTUAL_SALES);
            $Order->setSmartpayPaymentStatus($PaymentStatus);

            // TODO: Need to find a way to clear user cart here.
            $this->purchaseFlow->commit($Order, new PurchaseContext());
            $this->mailService->sendOrderMail($Order);
            $this->entityManager->flush();

            log_info("[Smartpay webhook] Order {$id} processed successfully.");
            return new JsonResponse(['success' => 'ok'], 200);
        } catch (\Exception $e) {
            log_error("[Smartpay webhook] SmartpayOrderId {$smartpayOrderId} process error", [
                'stacktrace' => $e->getTraceAsString(),
                'msg' => $e->getMessage()
            ]);
            return new JsonResponse(['success' => 'unknown exception'], 500);
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
                'id' => $id
            ]);

            if (null === $Order) {
                log_error("Order {$id} not found");
                $this->addError('受注情報が存在しません');
                return $this->redirectToRoute('shopping_error');
            }

            // Check order payment status
            if ($Order->getSmartpayPaymentStatus()->getId() == PaymentStatus::ACTUAL_SALES ||
                $Order->getSmartpayPaymentStatus()->getId() == PaymentStatus::PROVISIONAL_SALES) {
                // Already paid (probably by webhook), redirect to order complete page
                $this->cartService->clear();
                $this->session->set(OrderHelper::SESSION_ORDER_ID, $Order->getId());
                log_info("Order {$id} was found and paid, redirecting to order complete page");
                return $this->redirectToRoute('shopping_complete');
            } else if ($Order->getSmartpayPaymentStatus()->getId() != PaymentStatus::ENABLED) {
                // Not waiting for payment
                log_error("Order {$id} is not waiting for payment");
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
        $OrderStatus = $this->orderStatusRepository->find(OrderStatus::PROCESSING);
        $Order->setOrderStatus($OrderStatus);

        $this->purchaseFlow->rollback($Order, new PurchaseContext());
        $this->entityManager->flush();
    }

    /**
     * validateSignature validates the signature of the webhook request
     * 
     * @param string $signingSecret
     * @param string $signature
     * @param string $timestamp
     * @param string $body
     * @return bool
     */
    private function validateSignature(string $signingSecret, string $signature, string $timestamp, string $body): bool
    {
        $base62 = new Base62(["characters" => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789']);
        $calculatedSignature = hash_hmac('sha256', $timestamp . "." . $body, $base62->decode($signingSecret));
        return $calculatedSignature == $signature;
    }

}
