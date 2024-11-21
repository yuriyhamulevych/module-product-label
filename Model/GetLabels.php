<?php
/**
 * Copyright © Magefan (support@magefan.com). All rights reserved.
 * Please visit Magefan.com for license details (https://magefan.com/end-user-license-agreement).
 */
declare(strict_types=1);

namespace Magefan\ProductLabel\Model;

use Magefan\ProductLabel\Model\Config;
use Magento\Store\Model\StoreManagerInterface;
use Magefan\ProductLabel\Model\GetProductIdsToRuleIdsMap;
use Magefan\ProductLabel\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magefan\ProductLabel\Api\LabelProcessorInterface;
use Magento\Framework\View\LayoutInterface;

class GetLabels
{
    /**
     * @var Config
     */
    protected $config;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var GetProductIdsToRuleIdsMap
     */
    protected $getProductIdsToRuleIdsMap;

    /**
     * @var RuleCollectionFactory
     */
    protected $ruleCollectionFactory;

    /**
     * @var LabelProcessorInterface|null
     */
    protected $labelProcessor;

    /**
     * @var LayoutInterface
     */
    protected $layout;

    /**
     * GetLabels constructor.
     * @param StoreManagerInterface $storeManager
     * @param \Magefan\ProductLabel\Model\GetProductIdsToRuleIdsMap $getProductIdsToRuleIdsMap
     * @param RuleCollectionFactory $ruleCollectionFactory
     * @param LayoutInterface $layout
     * @param LabelProcessorInterface|null $labelProcessor
     */
    public function __construct(
        Config $config,
        StoreManagerInterface $storeManager,
        GetProductIdsToRuleIdsMap $getProductIdsToRuleIdsMap,
        RuleCollectionFactory     $ruleCollectionFactory,
        LayoutInterface $layout,
        ?LabelProcessorInterface $labelProcessor = null
    ) {
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->getProductIdsToRuleIdsMap = $getProductIdsToRuleIdsMap;
        $this->ruleCollectionFactory = $ruleCollectionFactory;
        $this->labelProcessor = $labelProcessor;
        $this->layout = $layout;
    }

    /**
     * @param array $productIds
     * @param $idsUseForProductPage
     * @return array
     */
    public function execute(array $productIds, $productIdsForProductPage = []): array
    {
        [$ruleIds, $productIdRuleIds] = $this->getProductIdsToRuleIdsMap->execute($productIds);

        $rules = $this->ruleCollectionFactory->create()
            ->addActiveFilter()
            ->addFieldToFilter('id', ['in' => $ruleIds])
            ->addStoreFilter($this->storeManager->getStore()->getId())
            ->setOrder('priority', 'asc');

        $replaceMap = [];

        foreach ($productIdRuleIds as $productId => $productRuleIds) {
            $forProductPage = in_array($productId, $productIdsForProductPage);

            $productLabels = $this->getAvailableProductLabels($rules, $productRuleIds, $forProductPage);

            $htmlToReplace = $this->layout
                ->createBlock(\Magefan\ProductLabel\Block\Label::class)
                ->setProductLabels($productLabels)
                ->setAdditionalCssClass($forProductPage ? 'mfpl-product-page' : '')
                ->toHtml();

            if ($htmlToReplace) {
                $replaceMap[$productId] = $htmlToReplace;
            }
        }

        if (null !== $this->labelProcessor) {
            $replaceMap = $this->labelProcessor->execute($replaceMap, $productIds);
        }

        return $replaceMap;
    }

    /**
     * @param $rules
     * @param $productRuleIds
     * @param bool $forProductPage
     * @return array
     */
    public function getAvailableProductLabels($rules, $productRuleIds, $forProductPage = false): array
    {
        $productLabelsCount = 0;
        $productLabels = [];

        $discardRulePerPosition = [];

        foreach ($rules as $rule) {
            if (in_array($rule->getId(), $productRuleIds)) {
                $productLabelsCount++;

                if ($forProductPage) {
                    $position = $rule->getPpPosition() ?: $rule->getPosition();
                } else {
                    $position = $rule->getPosition() ?: 'top-left';
                }

                if (!isset($discardRulePerPosition[$position])) {
                    $productLabels[$position][] = $rule->getLabelData($forProductPage);
                }

                if ($rule->getDiscardSubsequentRules()) {
                    $discardRulePerPosition[$position] = true;
                }
            }

            if ($productLabelsCount >= $this->config->getLabelsPerProduct()) {
                break;
            }
        }

        return $productLabels;
    }
}
