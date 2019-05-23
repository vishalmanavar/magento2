<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\PaypalGraphQl\Model\Resolver\Customer;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Webapi\Request;
use Magento\Paypal\Model\Api\Nvp;
use Magento\PaypalGraphQl\AbstractTest;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Model\QuoteIdToMaskedQuoteId;
use Magento\Framework\UrlInterface;
use Magento\Framework\App\Config\ConfigResource\ConfigInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;

/**
 * Test ExpressSetPaymentMethodTest graphql endpoint for customer
 *
 * @magentoAppArea graphql
 */
class PaypalExpressSetPaymentMethodTest extends AbstractTest
{
    /**
     * @var Http
     */
    private $request;

    /**
     * @var SerializerInterface
     */
    private $json;

    /**
     * @var QuoteIdToMaskedQuoteId
     */
    private $quoteIdToMaskedId;

    protected function setUp()
    {
        parent::setUp();

        $this->request = $this->objectManager->create(Http::class);
        $this->json = $this->objectManager->get(SerializerInterface::class);
        $this->quoteIdToMaskedId = $this->objectManager->get(QuoteIdToMaskedQuoteId::class);
    }

    /**
     * Test end to end test to process a paypal express order
     *
     * @return void
     * @dataProvider getPaypalCodesProvider
     * @magentoConfigFixture default_store paypal/wpp/sandbox_flag 1
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoDataFixture Magento/GraphQl/Quote/_files/customer/create_empty_cart.php
     * @magentoDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     * @magentoDataFixture Magento/GraphQl/Quote/_files/set_new_shipping_address.php
     * @magentoDataFixture Magento/GraphQl/Quote/_files/set_new_billing_address.php
     * @magentoDataFixture Magento/GraphQl/Quote/_files/set_flatrate_shipping_method.php
     */
    public function testResolve(string $paymentMethod): void
    {

        $payerId = 'SQFE93XKTSDRJ';
        $token = 'EC-TOKEN1234';
        $correlationId = 'c123456789';
        $reservedQuoteId = 'test_quote';

        $config = $this->objectManager->get(ConfigInterface::class);
        $config->saveConfig('payment/' . $paymentMethod .'/active', '1');

        if ($paymentMethod == 'payflow_express') {
            $config = $this->objectManager->get(ConfigInterface::class);
            $config->saveConfig('payment/payflow_link/active', '1');
        }

        $this->objectManager->get(ReinitableConfigInterface::class)->reinit();

        $cart = $this->getQuoteByReservedOrderId($reservedQuoteId);
        $cartId = $cart->getId();
        $maskedCartId = $this->quoteIdToMaskedId->execute((int) $cartId);

        $url = $this->objectManager->get(UrlInterface::class);
        $baseUrl = $url->getBaseUrl();

        $query = <<<QUERY
mutation {
    createPaypalExpressToken(input: {
        cart_id: "{$maskedCartId}",
        code: "{$paymentMethod}",
        urls: {
            return_url: "{$baseUrl}paypal/express/return/",
            cancel_url: "{$baseUrl}paypal/express/cancel/"
            success_url: "{$baseUrl}checkout/onepage/success/",
            pending_url: "{$baseUrl}checkout/onepage/pending/"
        }
        express_button: false
    })
    {
        __typename
        token
        paypal_urls{
            start
            edit
        }
        method
    }
    setPaymentMethodOnCart(input: {
        payment_method: {
          code: "{$paymentMethod}",
          additional_data: {
            paypal_express: {
              payer_id: "$payerId",
              token: "$token"
            }
            payflow_express: {
              payer_id: "$payerId",
              token: "$token"
            }
          }
        },
        cart_id: "{$maskedCartId}"})
      {
        cart {
          selected_payment_method {
            code
          }
        }
      }
      placeOrder(input: {cart_id: "{$maskedCartId}"}) {
        order {
          order_id
        }
      }
}
QUERY;

        $postData = $this->json->serialize(['query' => $query]);
        $this->request->setPathInfo('/graphql');
        $this->request->setMethod('POST');
        $this->request->setContent($postData);

        /** @var \Magento\Integration\Model\Oauth\Token $tokenModel */
        $tokenModel = $this->objectManager->create(\Magento\Integration\Model\Oauth\Token::class);
        $customerToken = $tokenModel->createCustomerToken(1)->getToken();

        $webApiRequest = $this->objectManager->get(Request::class);
        $webApiRequest->getHeaders()
            ->addHeaderLine('Content-Type', 'application/json')
            ->addHeaderLine('Accept', 'application/json')
            ->addHeaderLine('Authorization', 'Bearer ' . $customerToken);
        $this->request->setHeaders($webApiRequest->getHeaders());

        $paypalRequest = include __DIR__ . '/../../../_files/customer_paypal_create_token_request.php';
        $paypalResponse = [
            'TOKEN' => $payerId,
            'CORRELATIONID' => $correlationId,
            'ACK' => 'Success'
        ];

        if ($paymentMethod == 'payflow_express') {
            $paypalRequest['SOLUTIONTYPE'] = null;
        }

        $paypalRequest['AMT'] = '30.00';
        $paypalRequest['SHIPPINGAMT'] = '10.00';

        $this->nvpMock
            ->expects($this->at(0))
            ->method('call')
            ->with(Nvp::SET_EXPRESS_CHECKOUT, $paypalRequest)
            ->willReturn($paypalResponse);

        $paypalRequestDetails = [
            'TOKEN' => $token,
        ];

        $paypalRequestDetailsResponse = include __DIR__ . '/../../../_files/guest_paypal_set_payer_id.php';

        $this->nvpMock
            ->expects($this->at(1))
            ->method('call')
            ->with(Nvp::GET_EXPRESS_CHECKOUT_DETAILS, $paypalRequestDetails)
            ->willReturn($paypalRequestDetailsResponse);

        $paypalRequestPlaceOrder = include __DIR__ . '/../../../_files/guest_paypal_place_order.php';

        $paypalRequestPlaceOrder['EMAIL'] = 'customer@example.com';

        $this->nvpMock
            ->expects($this->at(2))
            ->method('call')
            ->with(Nvp::DO_EXPRESS_CHECKOUT_PAYMENT, $paypalRequestPlaceOrder)
            ->willReturn([
                'RESULT' => '0',
                'PNREF' => 'B7PPAC033FF2',
                'RESPMSG' => 'Approved',
                'AVSADDR' => 'Y',
                'AVSZIP' => 'Y',
                'TOKEN' => $token,
                'PAYERID' => $payerId,
                'PPREF' => '7RK43642T8939154L',
                'CORRELATIONID' => $correlationId,
                'PAYMENTTYPE' => 'instant',
                'PENDINGREASON' => 'authorization',
            ]);

        $response = $this->graphqlController->dispatch($this->request);
        $responseData = $this->json->unserialize($response->getContent());

        $this->assertArrayHasKey('data', $responseData);
        $this->assertArrayHasKey('createPaypalExpressToken', $responseData['data']);
        $createTokenData = $responseData['data']['createPaypalExpressToken'];

        $this->assertArrayNotHasKey('errors', $responseData);
        $this->assertEquals($paypalResponse['TOKEN'], $createTokenData['token']);
        $this->assertEquals($paymentMethod, $createTokenData['method']);
        $this->assertArrayHasKey('paypal_urls', $createTokenData);

        $this->assertTrue(
            isset($responseData['data']['setPaymentMethodOnCart']['cart']['selected_payment_method']['code'])
        );
        $this->assertEquals(
            $paymentMethod,
            $responseData['data']['setPaymentMethodOnCart']['cart']['selected_payment_method']['code']
        );

        $this->assertTrue(
            isset($responseData['data']['placeOrder']['order']['order_id'])
        );
        $this->assertEquals(
            'test_quote',
            $responseData['data']['placeOrder']['order']['order_id']
        );
    }

    /**
     * Paypal method codes provider
     *
     * @return array
     */
    public function getPaypalCodesProvider(): array
    {
        return [
            ['paypal_express'],
            ['payflow_express'],
        ];
    }
}
