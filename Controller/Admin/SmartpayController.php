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

namespace Plugin\Smartpay\Controller\Admin;


use Eccube\Controller\AbstractController;
use Eccube\Util\CacheUtil;
use Eccube\Util\StringUtil;
use Plugin\Smartpay\Form\Type\Admin\Smartpay\ConfigType;
use Plugin\Smartpay\Form\Type\Admin\Smartpay\APIKeys;
use Plugin\Smartpay\Form\Type\Admin\Smartpay\CallbackURLs;
use Plugin\Smartpay\Form\Type\Admin\Smartpay\WebhookURL;
use Plugin\Smartpay\Repository\ConfigRepository;
use Plugin\Smartpay\Client;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Class ConfigController
 * @package Plugin\Smartpay\Controller\Admin
 *
 * @Route("/%eccube_admin_route%/smartpay")
 */
class SmartpayController extends AbstractController
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    private $config;

    private $client;

    public function __construct(ConfigRepository $configRepository)
    {
        $this->configRepository = $configRepository;
        $this->config = $configRepository->get();
        $this->client = new Client(null, null, $this->config->getAPIPrefix());
    }

    /**
     * @param Request $request
     * @param CacheUtil $cacheUtil
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     *
     * @Route("/webhook_settings", name="smartpay_webhook_settings")
     * @Template("@Smartpay/admin/Smartpay/webhook_settings.twig")
     */
    public function webhookSettings(Request $request, CacheUtil $cacheUtil)
    {
        $state = 'create';
        $webhookId = getenv('SMARTPAY_WEBHOOK_ID');
        if ($webhookId) {
            try {
                $webhook = $this->client->httpGet("/webhook-endpoints/$webhookId");
                $state = 'created';
            } catch (\Exception $e) {
                log_error('Smartpay webhook fetch failed', [
                    'error' => $e->getMessage(),
                ]);
                $this->addWarning('smartpay.admin.config.webhook_settings.recreate_webhook.warning', 'admin');
                $state = 'recreate';
            }
        }
        $form = $this->createForm(WebhookURL::class, [
            'webhook_url' => $this->generateUrl('shopping_smartpay_payment_webhook', [], UrlGeneratorInterface::ABSOLUTE_URL)
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->clearMessage();
            $data = $form->getData();
            $webhookUrl = $data['webhook_url'];
            try {
                $newWebhook = $this->client->httpPost("/webhook-endpoints", [
                    'url' => $webhookUrl,
                    'description' => 'Created by EC-CUBE Smartpay plugin',
                    'eventSubscriptions' => [ 'order.authorized' ]
                ]);
                log_info('Smartpay webhook created', [
                    'webhook' => $newWebhook
                ]);
                $this->replaceOrAddEnv([
                    'SMARTPAY_WEBHOOK_ID' => $newWebhook['id'],
                    'SMARTPAY_WEBHOOK_SECRET' => $newWebhook['signingSecret']
                ]);

                $cacheUtil->clearCache();

                $this->addSuccess('admin.common.save_complete', 'admin');

                return $this->redirectToRoute('smartpay_admin_config');
            } catch (\Exception $e) {
                log_error('Smartpay webhook creation failed', [
                    'error' => $e->getMessage()
                ]);
                $this->addError('admin.common.save_error', 'admin');
                return $this->redirectToRoute('smartpay_webhook_settings');
            }
        }

        return [
            'state' => $state,
            'form' => $form->createView(),
            'webhook' => $webhook,
        ];
    }

    /**
     * @param Request $request
     * @param CacheUtil $cacheUtil
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     *
     * @Route("/callback_urls", name="smartpay_callback_urls")
     * @Template("@Smartpay/admin/Smartpay/callback_urls.twig")
     */
    public function callbackUrls(Request $request, CacheUtil $cacheUtil)
    {
        $form = $this->createForm(CallbackURLs::class, [
            'success_url' => getenv('SMARTPAY_SUCCESS_URL'),
            'cancel_url' => getenv('SMARTPAY_CANCEL_URL')
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $this->replaceOrAddEnv([
                'SMARTPAY_SUCCESS_URL' => $data['success_url'],
                'SMARTPAY_CANCEL_URL' => $data['cancel_url']
            ]);

            $cacheUtil->clearCache();

            $this->addSuccess('admin.common.save_complete', 'admin');

            return $this->redirectToRoute('smartpay_admin_config');
        }

        return [
            'form' => $form->createView()
        ];
    }

    /**
     * @param Request $request
     * @param CacheUtil $cacheUtil
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     *
     * @Route("/api_keys", name="smartpay_api_keys")
     * @Template("@Smartpay/admin/Smartpay/api_keys.twig")
     */
    public function apiKeys(Request $request, CacheUtil $cacheUtil)
    {
        $form = $this->createForm(APIKeys::class, [
            'public_key' => getenv('SMARTPAY_PUBLIC_KEY'),
            'secret_key' => getenv('SMARTPAY_SECRET_KEY')
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $this->replaceOrAddEnv([
                'SMARTPAY_PUBLIC_KEY' => $data['public_key'],
                'SMARTPAY_SECRET_KEY' => $data['secret_key']
            ]);

            $cacheUtil->clearCache();

            $this->addSuccess('admin.common.save_complete', 'admin');

            return $this->redirectToRoute('smartpay_admin_config');
        }

        return [
            'form' => $form->createView()
        ];
    }

    /**
     * @param Request $request
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     *
     * @Route("/config", name="smartpay_admin_config")
     * @Template("@Smartpay/admin/Smartpay/config.twig")
     */
    public function index(Request $request)
    {
        $Config = $this->configRepository->get();
        $form = $this->createForm(ConfigType::class, $Config);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $Config = $form->getData();
            $this->entityManager->persist($Config);
            $this->entityManager->flush();

            $this->addSuccess('smartpay.admin.save.success', 'admin');

            return $this->redirectToRoute('smartpay_admin_config');
        }

        return [
            'form' => $form->createView(),
            'public_key' => getenv('SMARTPAY_PUBLIC_KEY'),
            'success_url' => getenv('SMARTPAY_CANCEL_URL'),
            'cancel_url' => getenv('SMARTPAY_SUCCESS_URL'),
            'webhook_id' => getenv('SMARTPAY_WEBHOOK_ID'),

        ];
    }

    private function replaceOrAddEnv(array $replacement)
    {
        $envFile = $this->getParameter('kernel.project_dir') . DIRECTORY_SEPARATOR . '.env';
        if (file_exists($envFile)) {
            $env = file_get_contents($envFile);
            $env = StringUtil::replaceOrAddEnv($env, $replacement);
            file_put_contents($envFile, $env);
        }
    }
}
