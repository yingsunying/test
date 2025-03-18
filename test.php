<?php

namespace App\Service;

use App\Entity\Carrier;
use App\Entity\Order;
use App\Entity\Shipment;
use App\Entity\Status;
use App\Enum\CMTTrackingStatus;
use App\Message\CreateFulfillment;
use App\Type\FulfillmentStatusType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CMTReader
{
    const API_ENDPOINT = 'https://my.middleware.com';
    const ITEMS_PER_PAGE = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $httpClient,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Get list of events from middleware
     *
     * @param array $companies
     * @param array $parameters
     *
     * @return void
     */
    public function getEvent(array $companies, array $parameters = [], ?\DateTime $triggerDate = null): void
    {
        $messages = [];
        foreach ($companies as $company) {
            $companySettings = $company->getSettings();
            $securityToken = $companySettings['secretToken'];

            $headers = $this->getHeaders($securityToken);
            $limitTrigger = $triggerDate ?? new \DateTime('-15 minute');
            $limitTriggerDate = $limitTrigger->format('Y-m-d\TH:i:s');

            $eventsQueryParameters = array_merge(
                ['updatedAt[after]' => $limitTriggerDate],
                $parameters
            );
            $currentPage = 1; 
            do {
                try {
                    $responseArray = $this->fetchEvents($headers, $eventsQueryParameters, $currentPage);
                    $events = $responseArray['events'];
                    $totalItems = $responseArray['totalItems'];
                    foreach ($events as $event) {
                        try {
                            $messages = array_merge($messages, $this->processEvent($company, $event, $headers));
                        } catch (\Exception $e) {
                            error_log("Error processing event: " . $e->getMessage());
                        }
                    }
                } catch (\Exception $e) {
                    error_log("Error fetching events on page $currentPage: " . $e->getMessage());
                }

                $currentPage++;
            } while ($currentPage <= ceil($totalItems / self::ITEMS_PER_PAGE));
            $this->releaseMessages($messages);
        }
    }
    private function getHeaders(string $securityToken): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $securityToken,
        ];
    }
    private function fetchEvents(array $headers, array $queryParameters, int $page): array
    {
        $responseEvent = $this->httpClient->request(
            'GET',
            self::API_ENDPOINT . '/events',
            [
                'headers' => $headers,
                'query' => [
                    'page' => $page,
                    ...$queryParameters,
                ],
            ]
        );
        return $responseEvent->toArray();
    }
    private function processEvent($company, $event, $headers): array
    {
        $messages = [];
        $expeditionId = $event['reference']; 
        $eventStatusCode = $event['code']; 
        $eventCreatedAt = $event['createdAt']; 
        try {
            $expedition = $this->fetchExpedition($expeditionId, $headers); 
        } catch (\Exception $e) {
            $this->logger->error(\sprintf('Error processing CMT event %s. %s', $expeditionId, $e->getMessage()));
            return $messages; 
        }
        if (isset($expedition['id'])) { 
            $orderNumber = $expedition['numOrder']; 
            $myOrder = $this->getOrder($orderNumber, $company);  
            if ($myOrder) { 
                $this->updateOrder($myOrder, $expedition, $expeditionId);
                $shipment = $this->getShipment($company, $orderNumber, $myOrder->getWarehouse());

                if (!$shipment && in_array($eventStatusCode, ['shipped', 'delivered'])) { 
                    $this->createShipment($myOrder, $expedition, $expeditionId, $eventCreatedAt);
                    $messages = $this->createFulfillmentMessage($myOrder, $messages);
                }
                $this->em->flush();
            }
        }
        return $messages;
    }
    private function fetchExpedition(string $expeditionId, array $headers): array
    {
        $responseExpedition = $this->httpClient->request(
            'GET',
            self::API_ENDPOINT . '/expeditions/' . $expeditionId,
            [
                'headers' => $headers
            ]
        );
        return $responseExpedition->toArray();
    }
    private function getOrder(string $orderNumber, $company): ?Order
    {
        return $this->em
            ->getRepository(Order::class)
            ->findOneBy([
                'orderNumber' => $orderNumber,
                'company' => $company,
            ]);
    }
    private function getShipment($company, string $orderNumber, $warehouse): ?Shipment
    {
        return $this->em
            ->getRepository(Shipment::class)
            ->findOneBy([
                'company' => $company,
                'shipmentNumber' => $orderNumber,
                'shipFrom' => $warehouse,
            ]);
    }
    private function createFulfillmentMessage(Order $myOrder, array $messages): array
    {
        $fulfillmentStatusCode = $myOrder->getFulfillmentStatus()->getCode();
        if ($fulfillmentStatusCode !== FulfillmentStatusType::FULFILLED()->getValue()) {
            $fulfillmentMessage = new CreateFulfillment($myOrder->getId(), [$myOrder->getOrderNumber()]);
            $messages[] = $fulfillmentMessage;
        }
        return $messages;
    }
    private function releaseMessages(array $messages): void
    {
        foreach ($messages as $message) {
            $this->messageBus->dispatch($message);
        }
    }

    private function updateOrder(Order $order, array $expedition, string $expeditionId): void
    {
        $warehouse = $order->getWarehouse();
        $trackingUrl = self::API_ENDPOINT.'/tracking/'.$expeditionId;
        $carrierTrackingUrl = $expedition['trackingInformation']['carrierTrackingUrl'] ?? null;
        $externalTrackingNumber = $expedition['trackingInformation']['trackingNumber'] ?? null;
        $carrierNumber = $expedition['trackingInformation']['carrierNumber'] ?? null;
        $receiptNumber = $expedition['trackingInformation']['receiptNumber'] ?? null;
        $carrierCode = $expedition['carrier'] ?? null;
        $plannedDeliveryDate = $expedition['deliverySlot']['date'] ?? null;
        $deliveryTimeSlotAfter = $expedition['deliverySlot']['from'] ?? null;
        $deliveryTimeSlotBefore = $expedition['deliverySlot']['to'] ?? null;
        $transportationStatusCode = isset($expedition['trackingStatus']) ? (string) $expedition['trackingStatus'] : '';
        $transportationStatus = $this->em->getRepository(Status::class)
            ->findSalesOrderTransportationStatusFromCode($transportationStatusCode);
        $carrier = null;
        if ($carrierCode !== null) {
            $carrier = $this->em->getRepository(Carrier::class)->findOneBy(['code' => $carrierCode]);
        }
        $order->setActualCarrier($carrier);
        $order->setOrderTrackingUrl($trackingUrl);
        $order->setLastCarrierTrackingUrl($carrierTrackingUrl);
        $order->setTransportationStatus($transportationStatus);
        $order->setTrackingNumber($externalTrackingNumber);
        //unix
        if (is_numeric($plannedDeliveryDate)) {
            $formattedPlannedDeliveryDate = (new \DateTimeImmutable())->setTimestamp($plannedDeliveryDate);
        } else {
            $formattedPlannedDeliveryDate = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $plannedDeliveryDate);
        }

        $formattedDeliveryTimeSlotAfter = \DateTime::createFromFormat('H:i:s', $deliveryTimeSlotAfter);
        $formattedDeliveryTimeSlotBefore = \DateTime::createFromFormat('H:i:s', $deliveryTimeSlotBefore);
        if($formattedPlannedDeliveryDate !== false) {
            $order->setPlannedDeliveryDate( $formattedPlannedDeliveryDate);
        }
        if($formattedDeliveryTimeSlotAfter !== false) {
            $order->setDeliveryTimeSlotAfter($formattedDeliveryTimeSlotAfter);
        }
        if($formattedDeliveryTimeSlotBefore !== false) {
            $order->setDeliveryTimeSlotBefore($formattedDeliveryTimeSlotBefore);
        }
    }

    /**
     * @param Order $order
     * @param array $expedition
     * @param string $expeditionId
     * @param string $createdAt
     *
     * @return void
     */
    private function createShipment(Order $order, array $expedition, string $expeditionId, string $createdAt): void
    {
        $warehouse = $order->getWarehouse();
        $company = $order->getCompany();
        $companySettings = $company->getSettings();
        $externalTrackingNumber = $expedition['trackingInformation']['trackingNumber'] ?? null;
        $orderNumber = $expedition['numOrder'];
        $carrier = null;
        $trackingUrl = self::API_ENDPOINT.'/tracking/'.$expeditionId;
        $orderLineItems = $order->getOrderLineItems();
        $shipment = new Shipment();
        $shipment->setShipFrom($warehouse);
        $shipment->setShipTo($order->getShipTo());
        $shipment->setCompany($company);
        $shipment->setShipmentNumber($orderNumber);
        $shipment->setTotal($order->getTotal());
        $shipment->setTrackingUrl($trackingUrl);
        $shipment->setTrackingNumber($externalTrackingNumber);
        if ($carrier !== null) {
            $shipment->setCarrier($carrier);
        }
        $this->em->persist($shipment);
        $order->addShipment($shipment);
        $shipment->addOrder($order);
        foreach($orderLineItems as $orderLineItem) {
            $shipmentLine = new ShipmentLine();
            $shipmentLine->setQuantity($orderLineItem->getQuantityAllocated());
            $shipmentLine->setOwnerCode($orderLineItem->getOwnerCode());
            $shipmentLine->setLotNumber($orderLineItem->getLotNumber());
            $shipmentLine->setOrderLineItem($orderLineItem);
            $shipmentLine->setVariant($orderLineItem->getVariant());
            $shipmentLine->setTotalWeight($orderLineItem->getTotalWeight());
            $shipmentLine->setComposite($orderLineItem->getComposite());
            $shipmentLine->setShipment($shipment);
            $shipment->addShipmentLine($shipmentLine);
            $this->em->persist($shipmentLine);
        }
        $this->em->flush();
    }
}
