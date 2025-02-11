<?php

namespace Swarming\SubscribePro\Controller\Cards;

use Magento\Framework\DataObject;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use SubscribePro\Service\PaymentProfile\PaymentProfileInterface;

class Save extends \Magento\Customer\Controller\AbstractAccount
{
    /**
     * @var \Magento\Framework\Data\Form\FormKey\Validator
     */
    protected $formKeyValidator;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $customerSession;

    /**
     * @var \Swarming\SubscribePro\Model\Vault\Form
     */
    protected $vaultForm;

    /**
     * @var \Swarming\SubscribePro\Gateway\Config\VaultConfig
     */
    protected $platformVaultConfig;

    /**
     * @var \Swarming\SubscribePro\Gateway\Config\Config
     */
    protected $gatewayConfig;

    /**
     * @var \Swarming\SubscribePro\Model\Vault\Validator
     */
    protected $vaultFormValidator;

    /**
     * @var \Swarming\SubscribePro\Gateway\Command\VerifyCommand
     */
    protected $walletVerifyCommand;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Swarming\SubscribePro\Model\Vault\Form $vaultForm
     * @param \Swarming\SubscribePro\Gateway\Config\VaultConfig $platformVaultConfig
     * @param \Swarming\SubscribePro\Gateway\Config\Config $gatewayConfig
     * @param \Swarming\SubscribePro\Model\Vault\Validator $vaultFormValidator
     * @param \Swarming\SubscribePro\Gateway\Command\VerifyCommand $walletVerifyCommand
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator,
        \Magento\Customer\Model\Session $customerSession,
        \Swarming\SubscribePro\Model\Vault\Form $vaultForm,
        \Swarming\SubscribePro\Gateway\Config\VaultConfig $platformVaultConfig,
        \Swarming\SubscribePro\Gateway\Config\Config $gatewayConfig,
        \Swarming\SubscribePro\Model\Vault\Validator $vaultFormValidator,
        \Swarming\SubscribePro\Gateway\Command\VerifyCommand $walletVerifyCommand,
        \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
        $this->formKeyValidator = $formKeyValidator;
        $this->customerSession = $customerSession;
        $this->vaultForm = $vaultForm;
        $this->platformVaultConfig = $platformVaultConfig;
        $this->gatewayConfig = $gatewayConfig;
        $this->vaultFormValidator = $vaultFormValidator;
        $this->walletVerifyCommand = $walletVerifyCommand;
        $this->storeManager = $storeManager;
        parent::__construct($context);
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        /** @var \Magento\Framework\Controller\Result\Json $resultJson */
        $resultJson = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        if (!$this->formKeyValidator->validate($this->getRequest())
            || !$this->getRequest()->isPost()
            || !$this->platformVaultConfig->isActive()
        ) {
            $resultJson->setData(['state' => 'failed']);
            return $resultJson;
        }

        $data = (array)$this->getRequest()->getParams();
        unset($data['form_key']);

        try {
            $responseData = ['state' => 'succeeded', 'redirect' => '/'];

            if ($this->gatewayConfig->isWalletAuthorizationActive()) {
                $transfer = new DataObject();
                $commandSubject = $this->prepareVerifyCommandSubject($data, $transfer);

                $this->walletVerifyCommand->execute($commandSubject);

                $responseData['state'] = $transfer->getData('state');
                $responseData['token'] = $transfer->getData('token');
            } else {
                $this->vaultForm->createProfile($data, $this->customerSession->getCustomerId());
            }

            $resultJson->setData($responseData);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $resultJson->setData(['state' => 'failed']);
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('An error occurred while saving the card.'));
            $resultJson->setData(['state' => 'failed']);
        }

        return $resultJson;
    }

    /**
     * @param array $profileData
     * @param \Magento\Framework\DataObject $transfer
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function prepareVerifyCommandSubject(array $profileData, DataObject $transfer): array
    {
        if (empty($profileData['token'])) {
            throw new LocalizedException(__('The credit card can not be saved.'));
        }

        $profileData = $this->vaultFormValidator->validate($profileData);
        return [
            'store_id' => $this->storeManager->getStore()->getId(),
            'customer_id' => $this->customerSession->getCustomerId(),
            'creditcard_month' => $profileData[PaymentProfileInterface::CREDITCARD_MONTH],
            'creditcard_year' => $profileData[PaymentProfileInterface::CREDITCARD_YEAR],
            'billing_address' => $profileData[PaymentProfileInterface::BILLING_ADDRESS],
            'customer_email' => $this->customerSession->getCustomer()->getEmail(),
            'browser_info' => ($profileData['browser_info'] ?? ''),
            'token' => $profileData['token'],
            'transfer' => $transfer
        ];
    }
}
