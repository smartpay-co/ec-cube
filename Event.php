<?php

namespace Plugin\Smartpay;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class Event implements EventSubscriberInterface
{
    public function onCancel($event)
    {
        $Order = $event->getSubject()->getOrder();
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return ['workflow.order.transition.cancel' => 'onCancel'];
    }
}
