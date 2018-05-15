<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\InventoryShipping\Test\Integration;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\InventoryCatalogApi\Api\DefaultStockProviderInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\Inventory\Model\SourceItem\Command\GetSourceItemsBySkuInterface;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentItemCreationInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\Sales\Model\Order\Item as OrderItem;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SourceDeductionForBundleProductsOnDefaultStockTest extends TestCase
{
    /**
     * @var DefaultStockProviderInterface
     */
    private $defaultStockProvider;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var GetProductSalableQtyInterface
     */
    private $getProductSalableQty;

    /**
     * @var GetSourceItemsBySkuInterface
     */
    private $getSourceItemBySku;

    /**
     * @var ShipOrderInterface
     */
    private $shipOrder;

    /**
     * @var ShipmentItemCreationInterfaceFactory
     */
    private $shipmentItemCreationFactory;

    protected function setUp()
    {
        $this->searchCriteriaBuilder = Bootstrap::getObjectManager()->get(SearchCriteriaBuilder::class);
        $this->orderRepository = Bootstrap::getObjectManager()->get(OrderRepositoryInterface::class);
        $this->getSourceItemBySku = Bootstrap::getObjectManager()->get(GetSourceItemsBySkuInterface::class);
        $this->defaultStockProvider = Bootstrap::getObjectManager()->get(DefaultStockProviderInterface::class);
        $this->getProductSalableQty = Bootstrap::getObjectManager()->get(GetProductSalableQtyInterface::class);
        $this->shipOrder = Bootstrap::getObjectManager()->get(ShipOrderInterface::class);
        $this->shipmentItemCreationFactory = Bootstrap::getObjectManager()
            ->get(ShipmentItemCreationInterfaceFactory::class);
    }

    public function testSourceDeductionWhileShippingBundleWithShipmentSeparately()
    {
        $this->markTestIncomplete('https://github.com/magento-engcom/msi/issues/1154');
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', 'test_order_bundle_1')
            ->create();
        /** @var OrderInterface $order */
        $order = current($this->orderRepository->getList($searchCriteria)->getItems());
        /** @var \Magento\Sales\Model\Order\Item $item */
        $item = $this->getBundleOrderItemByShipmentType($order, AbstractType::SHIPMENT_SEPARATELY);

        $items = [];
        /** @var ShipmentItemCreationInterface $invoiceItemCreation */
        $shipmentItemCreation = $this->shipmentItemCreationFactory->create();
        $shipmentItemCreation->setOrderItemId($item->getId());
        $shipmentItemCreation->setQty(1);
        $items[] = $shipmentItemCreation;
        foreach ($item->getChildrenItems() as $childItem) {
            /** @var ShipmentItemCreationInterface $invoiceItemCreation */
            $shipmentItemCreation = $this->shipmentItemCreationFactory->create();
            $shipmentItemCreation->setOrderItemId($childItem->getId());
            $shipmentItemCreation->setQty(2);
            $items[] = $shipmentItemCreation;
        }

        $this->shipOrder->execute($order->getEntityId(), $items);

        /** @var SourceItemInterface $sourceItem */
        $sourceItem = current($this->getSourceItemBySku->execute('SKU-1')->getItems());
        self::assertEquals(8, $sourceItem->getQuantity());

        /** @var SourceItemInterface $sourceItem */
        $sourceItem = current($this->getSourceItemBySku->execute('SKU-3')->getItems());
        self::assertEquals(28, $sourceItem->getQuantity());

        $salableQty = $this->getProductSalableQty->execute('SKU-1', $this->defaultStockProvider->getId());
        self::assertEquals(4, $salableQty);

        $salableQty = $this->getProductSalableQty->execute('SKU-3', $this->defaultStockProvider->getId());
        self::assertEquals(4, $salableQty);
    }

    public function testSourceDeductionWhileShippingBundleWithShipmentTogether()
    {
        $this->markTestIncomplete('https://github.com/magento-engcom/msi/issues/1154');
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', 'test_order_bundle_1')
            ->create();
        /** @var OrderInterface $order */
        $order = current($this->orderRepository->getList($searchCriteria)->getItems());
        /** @var \Magento\Sales\Model\Order\Item $item */
        $item = $this->getBundleOrderItemByShipmentType($order, AbstractType::SHIPMENT_TOGETHER);

        /** @var ShipmentItemCreationInterface $invoiceItemCreation */
        $shipmentItemCreation = $this->shipmentItemCreationFactory->create();
        $shipmentItemCreation->setOrderItemId($item->getId());
        $shipmentItemCreation->setQty(2);

        $this->shipOrder->execute($order->getEntityId(), [$shipmentItemCreation]);

        /** @var SourceItemInterface $sourceItem */
        $sourceItem = current($this->getSourceItemBySku->execute('SKU-2')->getItems());
        self::assertEquals(10, $sourceItem->getQuantity());

        /** @var SourceItemInterface $sourceItem */
        $sourceItem = current($this->getSourceItemBySku->execute('SKU-3')->getItems());
        self::assertEquals(18, $sourceItem->getQuantity());

        $salableQty = $this->getProductSalableQty->execute('SKU-2', $this->defaultStockProvider->getId());
        self::assertEquals(5, $salableQty);

        $salableQty = $this->getProductSalableQty->execute('SKU-3', $this->defaultStockProvider->getId());
        self::assertEquals(4, $salableQty);
    }

    /**
     * Get order item for bundle product by shipment type
     *
     * @param OrderInterface $order
     * @param int $type
     * @return OrderItem
     */
    private function getBundleOrderItemByShipmentType(OrderInterface $order, int $type): OrderItem
    {
        foreach ($order->getAllVisibleItems() as $item) {
            if ($item->getProduct()->getShipmentType() == $type) {
                return $item;
            }
        }
    }
}
