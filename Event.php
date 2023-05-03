<?php

namespace Plugin\Smartpay;

use Plugin\Smartpay\Entity\PaymentStatus;
use Plugin\Smartpay\Repository\ConfigRepository;
use Plugin\Smartpay\Repository\PaymentStatusRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Doctrine\ORM\EntityManagerInterface;

class Event implements EventSubscriberInterface
{

    private $entityManager;
    private $paymentStatusRepository;
    private $config;
    private $client;

    public function __construct(EntityManagerInterface $entityManager, PaymentStatusRepository $paymentStatusRepository,ConfigRepository $configRepository)
    {
        $this->entityManager = $entityManager;
        $this->paymentStatusRepository = $paymentStatusRepository;
        $this->config = $configRepository->get();
        $this->client = new Client(null, null, $this->config->getAPIPrefix());
    }

    public function onCancel($event)
    {
        log_info("[Smartpay] Cancel order");
        try {

            $Order = $event->getSubject()->getOrder();
            $checkoutSessionID = $Order->getSmartpayPaymentCheckoutID();

            // Check if SmartpayPaymentCheckoutID exists
            if (empty($checkoutSessionID)) {
                log_info("[Smartpay] Skipping refund, SmartpayPaymentCheckoutId not found.");
                return;
            }

            // Check if SmartpayPaymentStatus is refundable
            if ($Order->getSmartpayPaymentStatus() != $this->paymentStatusRepository->find(PaymentStatus::ACTUAL_SALES) &&
                $Order->getSmartpayPaymentStatus() != $this->paymentStatusRepository->find(PaymentStatus::PROVISIONAL_SALES)) {
                log_info("[Smartpay] Skipping refund, SmartpayPaymentStatus is not refundable.");
                return;
            }

            // Check if the order is refundable
            $checkoutSessionID = $Order->getSmartpayPaymentCheckoutID();
            $checkoutSession = $this->client->httpGet("/checkout-sessions/{$checkoutSessionID}?expand=all");
            if ($checkoutSession['order']['status'] != 'succeeded') {
                log_error("[Smartpay] Refund skipped, Smartpay order status is {$checkoutSession['order']['status']}");
                return;
            }

	    // Refund
	    // We only to automatic capture when checkout, so we just refund the first payment here.
	    // We might need to handle cancel smartpay order & refund multiple payments here 
	    // if we support manual capture in the future.
            $data = [
                'amount' => $checkoutSession['order']['amount'],
                'currency' => $checkoutSession['order']['currency'],
                'payment' => $checkoutSession['order']['payments'][0]['id'],
                'reason' => 'requested_by_customer',
                'reference' => "{$Order->getId()}"
            ];
            $refund = $this->client->httpPost("/refunds", $data);
            log_info("[Smartpay] Order {$Order->getId()} refunded. Smartpay refund ID: {$refund['id']}");

            // Mark Order.SmartpayPaymentStatus as Cancel
            $PaymentStatus = $this->paymentStatusRepository->find(PaymentStatus::CANCEL);
            $Order->setSmartpayPaymentStatus($PaymentStatus);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            log_error('[Smartpay] Refund fail:', [ 'msg' => $e->getMessage()]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return ['workflow.order.transition.cancel' => 'onCancel'];
    }
}
