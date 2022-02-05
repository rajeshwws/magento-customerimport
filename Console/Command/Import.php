<?php
declare(strict_types=1);

namespace Rajeshwws\Customerimport\Console\Command;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\Console\Cli;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\File\Csv;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Customer Import
 */
class Import extends Command
{
    /**
     *
     */
    private const PROFILE_NAME_ARGUMENT = "profile-name";
    /**
     *
     */
    private const CSV_FILE_ARGUMENT = "csv-file-name";
    /**
     * @var DirectoryList
     */
    private $directoryList;
    /**
     * @var File
     */
    private $file;
    /**
     * @var Csv
     */
    private $csv;
    /**
     * @var CustomerFactory
     */
    private $customerFactory;
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;
    /**
     * @var \Magento\Customer\Api\Data\CustomerInterface
     */
    private $customer;
    /**
     * @var CustomerInterfaceFactory
     */
    private $customerInterfaceFactory;
    /**
     * @var Encryptor
     */
    private $encryptor;

    /**
     * @var string[]
     */
    private $supportedFileFormat = ['csv','json'];
    /**
     * @var Json
     */
    private $json;
    /**
     * @var \Magento\Framework\Filesystem\Io\File
     */
    private $ioFile;

    /**
     * @param DirectoryList $directoryList
     * @param File $file
     * @param Csv $csv
     * @param CustomerInterfaceFactory $customerInterfaceFactory
     * @param StoreManagerInterface $storeManager
     * @param CustomerRepositoryInterface $customerRepository
     * @param Encryptor $encryptor
     * @param Json $json
     * @param \Magento\Framework\Filesystem\Io\File $ioFile
     */
    public function __construct(
        DirectoryList $directoryList,
        File $file,
        Csv $csv,
        CustomerInterfaceFactory $customerInterfaceFactory,
        StoreManagerInterface $storeManager,
        CustomerRepositoryInterface $customerRepository,
        Encryptor $encryptor,
        Json $json,
        \Magento\Framework\Filesystem\Io\File $ioFile
    ) {
        $this->directoryList = $directoryList;
        $this->file = $file;
        $this->csv = $csv;
        $this->storeManager = $storeManager;
        $this->customerRepository = $customerRepository;
        $this->customerInterfaceFactory = $customerInterfaceFactory;
        $this->encryptor = $encryptor;
        $this->json = $json;
        $this->ioFile = $ioFile;
        parent::__construct();
    }

    /**
     * @inheritdoc
     * @throws \Exception
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ) {
        $profileName = $input->getArgument(self::PROFILE_NAME_ARGUMENT);
        $csvFileName = $input->getArgument(self::CSV_FILE_ARGUMENT);
        $rootDirectory = $this->directoryList->getRoot();
        $csvFile = $rootDirectory . "/var/import/" . $csvFileName;
        $pathInfo = $this->ioFile->getPathInfo($csvFile);
        $fileExtension = $pathInfo['extension'];
        if (!in_array($fileExtension, $this->supportedFileFormat)) {
            $output->writeln("<error>Invalid File Format. Only csv or json file format is supported</error>");
            return Cli::RETURN_FAILURE;
        }
        if ($this->file->isExists($csvFile)) {
            $websiteId = $this->storeManager->getStore()->getWebsiteId();
            $existCount = 0;
            $createCount = 0;
            if ($fileExtension === 'csv') {
                $this->csv->setDelimiter(",");
                $data = $this->csv->getData($csvFile);
                if (!empty($data)) {
                    foreach (array_slice($data, 1) as $key => $value) {
                        try {
                            $this->customerRepository->get($value[2], $websiteId);
                            $existCount++;
                            continue;
                        } catch (NoSuchEntityException|LocalizedException $e) {
                        }
                        $customer = $this->customerInterfaceFactory->create();
                        $customer->setWebsiteId($websiteId);
                        $password = $this->getRandomPassword();
                        $passwordHash = $this->encryptor->getHash($password, true);
                        $customer->setWebsiteId($websiteId);
                        $customer->setFirstname($value[0]);
                        $customer->setLastname($value[1]);
                        $customer->setEmail($value[2]);
                        $this->customerRepository->save($customer, $passwordHash);
                        $createCount++;
                    }
                }
            } elseif ($fileExtension === 'json') {
                $json = $this->file->fileGetContents($csvFile);
                $data = $this->json->unserialize($json);
                foreach ($data as $value) {
                    try {
                        $this->customerRepository->get($value['emailaddress'], $websiteId);
                        $existCount++;
                        continue;
                    } catch (NoSuchEntityException|LocalizedException $e) {
                    }
                    $customer = $this->customerInterfaceFactory->create();
                    $customer->setWebsiteId($websiteId);
                    $password = $this->getRandomPassword();
                    $passwordHash = $this->encryptor->getHash($password, true);
                    $customer->setWebsiteId($websiteId);
                    $customer->setFirstname($value['fname']);
                    $customer->setLastname($value['lname']);
                    $customer->setEmail($value['emailaddress']);
                    $this->customerRepository->save($customer, $passwordHash);
                    $createCount++;
                }
            }
            $output->writeln("<info>Customer Created:</info>" . $createCount . "\n" . "<info>Customer Already Exist:</info>" . $existCount);//phpcs:ignore
            return Cli::RETURN_SUCCESS;
        } else {
            $output->writeln("<error>Given file not exist insider var/import directory.</error>");
            return Cli::RETURN_FAILURE;
        }
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName("customer:import");
        $this->setDescription("Import customer using csv and json");
        $this->setDefinition([
            new InputArgument(self::PROFILE_NAME_ARGUMENT, InputArgument::REQUIRED, "Profile Name"),
            new InputArgument(self::CSV_FILE_ARGUMENT, InputArgument::REQUIRED, "CSV/JSON FILE")
        ]);
        parent::configure();
    }

    /**
     * Generate password
     *
     * @return false|string
     */
    private function getRandomPassword()
    {
        $length = 8;
        $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789$&";
        return substr(str_shuffle($chars), 0, $length);
    }
}
