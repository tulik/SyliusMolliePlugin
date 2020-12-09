<?php

declare(strict_types=1);

namespace BitBag\SyliusMolliePlugin\Controller\Action\Shop;

use BitBag\SyliusMolliePlugin\Provider\Apple\ApplePayDirectProviderInterface;
use Sylius\Bundle\OrderBundle\Controller\OrderController as BaseOrderController;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Resource\Exception\UpdateHandlingException;
use Sylius\Component\Resource\ResourceActions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OrderController extends BaseOrderController
{
    public function updateAppleOrderAction(Request $request): Response
    {
        $configuration = $this->requestConfigurationFactory->create($this->metadata, $request);

        $this->isGrantedOr403($configuration, ResourceActions::UPDATE);
        $resource = $this->findOr404($configuration);

        /** @var ResourceControllerEvent $event */
        $event = $this->eventDispatcher->dispatchPreEvent(ResourceActions::UPDATE, $configuration, $resource);

        if ($event->isStopped() && !$configuration->isHtmlRequest()) {
            throw new HttpException($event->getErrorCode(), $event->getMessage());
        }

        if ($event->isStopped()) {
            $eventResponse = $event->getResponse();
            if (!empty($eventResponse)) {
                return $eventResponse;
            }

            return new JsonResponse([], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->getApplePayProviderService()->provideOrder($this->getCurrentCart(), $request);

            /** @var PaymentInterface $payment */
            $payment = $this->getCurrentCart()->getLastPayment();

            if ($payment->getState() !== PaymentInterface::STATE_COMPLETED) {
                $response = [
                    'status' => 1,
                    'errors' => 'Payment not created',
                ];

                return new JsonResponse($response, Response::HTTP_BAD_REQUEST);
            }

            $this->resourceUpdateHandler->handle($resource, $configuration, $this->manager);
        } catch (UpdateHandlingException $exception) {
            return new JsonResponse([], Response::HTTP_BAD_REQUEST);
        }

        $postEvent = $this->eventDispatcher->dispatchPostEvent(ResourceActions::UPDATE, $configuration, $resource);

        $postEventResponse = $postEvent->getResponse();

        if (!empty($postEventResponse)) {
            return $postEventResponse;
        }

        $initializeEvent = $this->eventDispatcher->dispatchInitializeEvent(ResourceActions::UPDATE, $configuration, $resource);

        $initializeEventResponse = $initializeEvent->getResponse();

        if (!empty($initializeEventResponse)) {
            return $initializeEventResponse;
        }

        $redirect = $this->redirectToRoute('sylius_shop_order_thank_you');
        $dataResponse['returnUrl'] = $redirect->getTargetUrl();
        $dataResponse['responseToApple'] = ['status' => 0];

        $response = [
            'success' => true,
            'data' => $dataResponse,
        ];

        return new JsonResponse($response, Response::HTTP_OK);
    }

    private function getApplePayProviderService(): ApplePayDirectProviderInterface
    {
        return $this->get('bitbag_sylius_mollie_plugin.provider.apple.apple_pay_direct_provider');
    }
}
