<?php

/*
 * This file is part of the Harvest Cloud package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace HarvestCloud\MarketPlace\BuyerBundle\Controller;

use HarvestCloud\MarketPlace\BuyerBundle\Controller\BuyerController as Controller;
use HarvestCloud\PayPalBundle\Util\PaymentGateway;
use HarvestCloud\PayPalBundle\Entity\PayPalPaymentCollection;
use HarvestCloud\CoreBundle\Entity\OrderCollection;

/**
 * CheckoutController
 *
 * @author Tom Haskins-Vaughan <tom@harvestcloud.com>
 * @since  2012-04-11
 */
class CheckoutController extends Controller
{
    /**
     * pickup_windows_for_collection
     *
     * Available pickup windows for Sellers
     *
     * @author Tom Haskins-Vaughan <tom@harvestcloud.com>
     * @since  2012-05-18
     * @todo   Deal better with caught exception
     *
     * @return Response A Response instance
     */
    public function select_pickup_window_for_collectionAction($hub_id, $start_time)
    {
        $orderCollection = $this->getOrderCollection();

        $em = $this->getDoctrine()->getEntityManager();

        try
        {
            foreach ($orderCollection->getOrders() as $order)
            {
                // Find SellerHubPickupWindow
                $pickupWindow = $this->getRepo('SellerHubPickupWindow')
                    ->findOneForHubIdAndStartTime($hub_id, $start_time);

                if (null === $pickupWindow)
                {
                    throw new \Exception('No pickup window found');
                }

                $order->setPickupWindow($pickupWindow);
                $em->persist($order);
            }

            $em->flush();
        }
        catch (\Exception $e)
        {
            exit('There was a problem assigning pickup windows');
        }

        return $this->redirect($this->generateUrl('Buyer_checkout_review'));
    }

    /**
     * select_pickup_window_for_collection
     *
     * Buyer selects desired PickupWindow
     *
     * @author Tom Haskins-Vaughan <tom@harvestcloud.com>
     * @since  2012-05-18
     *
     * @return Response A Response instance
     */
    public function pickup_windows_for_collectionAction()
    {
        $orderCollection = $this->getOrderCollection();

        return $this->render('HarvestCloudMarketPlaceBuyerBundle:Checkout:select_pickup_window.html.twig', array(
          'orderCollection' => $orderCollection,
        ));
    }

    /**
     * review
     *
     * Buyer reviews all parts of order one last time before
     * placing order
     *
     * @author Tom Haskins-Vaughan <tom@harvestcloud.com>
     * @since  2012-04-11
     *
     * @return Response A Response instance
     */
    public function reviewAction()
    {
        return $this->render('HarvestCloudMarketPlaceBuyerBundle:Checkout:review.html.twig');
    }

    /**
     * place_order
     *
     * @author Tom Haskins-Vaughan <tom@harvestcloud.com>
     * @since  2012-04-11
     *
     * @return Response A Response instance
     */
    public function place_orderAction()
    {
        $session = $this->getRequest()->getSession();

        $orderCollection = $this->getRepo('OrderCollection')
            ->find($session->get('cart_id'));

        $paymentCollection = $orderCollection->getPayPalPaymentCollection();

        $paymentGateway = new PaymentGateway();
        $responseArray = $paymentGateway->processOrderPayments($paymentCollection);

        $em = $this->getDoctrine()->getEntityManager();
        $em->persist($paymentCollection);
        $em->flush();

        return $this->redirect('https://www.sandbox.paypal.com/webscr?cmd=_ap-payment&paykey='.$responseArray['payKey']);
    }

    /**
     * complete_paypal
     *
     * @author Tom Haskins-Vaughan <tom@harvestcloud.com>
     * @since  2012-05-08
     *
     * @return Response A Response instance
     */
    public function complete_paypalAction()
    {
        $session = $this->getRequest()->getSession();

        $orderCollection = $this->getRepo('OrderCollection')
            ->find($session->get('cart_id'));

        $paymentCollection = $orderCollection->getPayPalPaymentCollection();

        $paymentGateway = new PaymentGateway();
        $paymentGateway->completeOrderPayments($paymentCollection);

        $em = $this->getDoctrine()->getEntityManager();

        foreach ($orderCollection->getOrders() as $order)
        {
            $order->place();
            $em->persist($order);

            // Seller Journal entry
            $journal = new \HarvestCloud\DoubleEntryBundle\Entity\Journal();
            $journal->setType('PAYMENT');

            // Bank posting
            $bankPosting = new \HarvestCloud\DoubleEntryBundle\Entity\Posting();
            $bankPosting->setAccount($order->getSeller()->getBankAccount());
            $bankPosting->setAmount($order->getAmountForPaymentGateway());

            // A/P posting
            $apPosting = new \HarvestCloud\DoubleEntryBundle\Entity\Posting();
            $apPosting->setAccount($order->getSeller()->getAPAccount());
            $apPosting->setAmount(-1*$order->getAmountForPaymentGateway());

            $journal->addPosting($bankPosting);
            $journal->addPosting($apPosting);

            $em->persist($journal);
        }

        $em->persist($orderCollection);
        $em->persist($paymentCollection);
        $em->flush();

        return $this->redirect($this->generateUrl('Buyer_checkout_receipt'));
    }

    /**
     * receipt
     *
     * @author Tom Haskins-Vaughan <tom@harvestcloud.com>
     * @since  2012-04-11
     *
     * @return Response A Response instance
     */
    public function receiptAction()
    {
        $session = $this->getRequest()->getSession();
        $session->set('cart_id', null);

        return $this->render('HarvestCloudMarketPlaceBuyerBundle:Checkout:receipt.html.twig');
    }

    /**
     * Get OrderCollection
     *
     * @author Tom Haskins-Vaughan <tom@harvestcloud.com>
     * @since  2012-05-18
     *
     * @return OrderCollection
     */
    protected function getOrderCollection()
    {
        $session = $this->getRequest()->getSession();

        $orderCollection = $this->getRepo('OrderCollection')
            ->find($session->get('cart_id'));

        return $orderCollection;
    }
}