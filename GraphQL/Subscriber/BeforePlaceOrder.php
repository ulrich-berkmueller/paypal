<?php
/**
 * This file is part of OXID eSales PayPal module.
 *
 * OXID eSales PayPal module is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OXID eSales PayPal module is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with OXID eSales PayPal module.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @link      http://www.oxid-esales.com
 * @copyright (C) OXID eSales AG 2003-2018
 */

declare(strict_types=1);

namespace OxidEsales\PayPalModule\GraphQL\Subscriber;

use OxidEsales\GraphQL\Storefront\Basket\Event\BeforePlaceOrder as OriginalEvent;
use OxidEsales\GraphQL\Storefront\Basket\Service\Basket as StorefrontBasketService;
use OxidEsales\GraphQL\Storefront\Basket\Service\BasketRelationService;
use OxidEsales\GraphQL\Storefront\Shared\Infrastructure\Basket as SharedBasketInfrastructure;
use OxidEsales\PayPalModule\GraphQL\DataType\BasketExtendType;
use OxidEsales\PayPalModule\GraphQL\Exception\BasketCommunication;
use OxidEsales\PayPalModule\GraphQL\Service\Basket as BasketService;
use OxidEsales\PayPalModule\GraphQL\Service\Payment as PaymentService;
use OxidEsales\PayPalModule\Model\Response\ResponseGetExpressCheckoutDetails;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use OxidEsales\Eshop\Core\Registry as EshopRegistry;

use OxidEsales\Eshop\Application\Model\Address as EshopAddressModel;

class BeforePlaceOrder implements EventSubscriberInterface
{
    /** @var StorefrontBasketService */
    private $storefrontBasketService;

    /** @var SharedBasketInfrastructure */
    private $sharedBasketInfra;

    /** @var BasketRelationService */
    private $basketRelationService;

    /** @var PaymentService */
    private $paymentService;

    /** @var BasketService */
    private $basketService;

    public function __construct(
        StorefrontBasketService $storefrontBasketService,
        SharedBasketInfrastructure $sharedBasketInfra,
        BasketRelationService $basketRelationService,
        PaymentService $paymentService,
        BasketService $basketService
    ) {
        $this->storefrontBasketService = $storefrontBasketService;
        $this->sharedBasketInfra = $sharedBasketInfra;
        $this->basketRelationService  = $basketRelationService;
        $this->paymentService = $paymentService;
        $this->basketService = $basketService;
    }

    public function handle(OriginalEvent $event): OriginalEvent
    {
        $userBasket = $this->storefrontBasketService->getAuthenticatedCustomerBasket((string)$event->getBasketId());
        if ($this->basketService->checkBasketPaymentMethodIsPayPal($userBasket)) {
            $extendUserBasket = new BasketExtendType();

            $token = $extendUserBasket->paypalToken($userBasket);
            if (!$token) {
                throw BasketCommunication::notStarted($userBasket->id()->val());
            }

            //call PayPal API once for ExpressCheckoutDetails
            /** @var ResponseGetExpressCheckoutDetails $expressCheckoutDetails */
            $expressCheckoutDetails = $this->paymentService->getExpressCheckoutDetails($token);

            $tokenStatus = $this->paymentService->getPayPalTokenStatus($token, $expressCheckoutDetails);
            if (!$tokenStatus->getStatus()) {
                throw BasketCommunication::notConfirmed($userBasket->id()->val());
            }

            $sessionBasket = $this->paymentService->getValidEshopBasketModel($userBasket, $expressCheckoutDetails);

            // In order to be able to finalize order, using PayPal as payment method,
            // we need to prepare the following session variables.
            $session = EshopRegistry::getSession();
            $session->setBasket($sessionBasket);
            $session->setVariable('oepaypal-token', $token);
            $session->setVariable('oepaypal-payerId', $tokenStatus->getPayerId());
        }

        return $event;
    }

    public static function getSubscribedEvents()
    {
        return [
            OriginalEvent::NAME => 'handle'
        ];
    }
}