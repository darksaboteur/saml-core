<?php
/**
 * Created by PhpStorm.
 * User: dsmrt
 * Date: 2/10/18
 * Time: 10:47 PM
 */

namespace flipbox\saml\core\controllers;

use Craft;
use flipbox\keychain\records\KeyChainRecord;
use flipbox\saml\core\controllers\cp\view\AbstractController;
use flipbox\saml\core\controllers\cp\view\metadata\AbstractEditController;
use flipbox\saml\core\controllers\cp\view\metadata\VariablesTrait;
use flipbox\saml\core\exceptions\InvalidMetadata;
use flipbox\saml\core\helpers\SerializeHelper;
use flipbox\saml\core\models\GroupOptions;
use flipbox\saml\core\models\SettingsInterface;
use flipbox\saml\core\records\AbstractProvider;
use flipbox\saml\core\records\ProviderInterface;
use yii\web\NotFoundHttpException;

abstract class AbstractMetadataController extends AbstractController implements \flipbox\saml\core\EnsureSAMLPlugin
{

    use VariablesTrait;

    /**
     * @return string
     * @throws InvalidMetadata
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionIndex()
    {

        $this->requireAdmin();

        /** @var AbstractProvider $provider */
        $provider = $this->getPlugin()->getProvider()->findByEntityId(
            $this->getPlugin()->getSettings()->getEntityId()
        )->one();

        if (! $provider) {
            throw new InvalidMetadata('Metadata for this server is missing. Please configure this plugin.');
        }

        SerializeHelper::xmlContentType();
        return $provider->toXmlString();
    }

    /**
     * @return \yii\web\Response
     * @throws \Exception
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionAutoCreate()
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $record = $this->processSaveAction();

        $entityDescriptor = $this->getPlugin()->getMetadata()->create(
            $this->getPlugin()->getSettings(),
            $record->keychain
        );

        $provider = $this->getPlugin()->getProvider()->create(
            $entityDescriptor,
            $record->keychain
        );

        $record->entityId = $provider->getEntityId();
        $record->metadata = $provider->metadata;
        $record->setMetadataModel($provider->getMetadataModel());


        if (! $this->getPlugin()->getProvider()->save($record)) {
            return $this->renderTemplate(
                $this->getTemplateIndex() . AbstractEditController::TEMPLATE_INDEX . DIRECTORY_SEPARATOR . 'edit',
                array_merge(
                    [
                        'provider' => $record,
                        'keychain' => $record->keychain ?: new KeyChainRecord(),
                    ],
                    $this->prepVariables($record)
                )
            );
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * @return \yii\web\Response
     * @throws \Exception
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionSave()
    {

        $this->requireAdmin();
        $this->requirePostRequest();

        /** @var AbstractProvider $record */
        $record = $this->processSaveAction();

        if ($record->hasErrors() || ! $this->getPlugin()->getProvider()->save($record)) {
            return $this->renderTemplate(
                $this->getTemplateIndex() . AbstractEditController::TEMPLATE_INDEX . DIRECTORY_SEPARATOR . 'edit',
                array_merge(
                    [
                        'provider' => $record,
                        'keychain' => $record->keychain ?: new KeyChainRecord(),
                    ],
                    $this->prepVariables($record)
                )
            );
        }

        Craft::$app->getSession()->setNotice(Craft::t($this->getPlugin()->getHandle(), 'Provider saved.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Actions
     */

    /**
     * @return \yii\web\Response
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\web\ForbiddenHttpException
     * @throws \Exception
     */
    public function actionChangeStatus()
    {

        $this->requireAdmin();
        $this->requirePostRequest();

        $providerId = Craft::$app->request->getRequiredBodyParam('identifier');

        /** @var string $recordClass */
        $recordClass = $this->getPlugin()->getProviderRecordClass();

        /** @var AbstractProvider $record */
        $record = $recordClass::find()->where([
            'id' => $providerId,
        ])->one();

        $record->enabled = ! $record->enabled;

        if (! $this->getPlugin()->getProvider()->save($record)) {
            return $this->renderTemplate(
                $this->getTemplateIndex() . AbstractEditController::TEMPLATE_INDEX . DIRECTORY_SEPARATOR . 'edit',
                array_merge(
                    [
                        'provider' => $record,
                        'keychain' => $record->keychain ?: new KeyChainRecord(),
                    ],
                    $this->prepVariables($record)
                )
            );
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * @return \yii\web\Response
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionDelete()
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        $providerId = Craft::$app->request->getRequiredBodyParam('identifier');

        /** @var string $recordClass */
        $recordClass = $this->getPlugin()->getProviderRecordClass();

        /** @var ProviderInterface $record */
        $record = $recordClass::find()->where([
            'id' => $providerId,
        ])->one();

        if (! $this->getPlugin()->getProvider()->delete($record)) {
            return $this->renderTemplate(
                $this->getTemplateIndex() . AbstractEditController::TEMPLATE_INDEX . DIRECTORY_SEPARATOR . 'edit',
                array_merge(
                    [
                        'provider' => $record,
                        'keychain' => $record->keychain ?: new KeyChainRecord(),
                    ],
                    $this->prepVariables($record)
                )
            );
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * @return ProviderInterface
     * @throws \Exception
     */
    protected function processSaveAction()
    {

        $providerId = Craft::$app->request->getParam('identifier');
        $keyId = Craft::$app->request->getParam('keychain');
        $providerType = Craft::$app->request->getParam('providerType');
        $metadata = Craft::$app->request->getParam('metadata');
        $mapping = Craft::$app->request->getParam('mapping', []);
        $label = Craft::$app->request->getRequiredParam('label');
        $nameIdOverride = Craft::$app->request->getParam('nameIdOverride');

        $plugin = $this->getPlugin();

        $recordClass = $this->getPlugin()->getProviderRecordClass();
        /** @var AbstractProvider $record */
        if ($providerId) {
            $record = $recordClass::find()->where([
                'id' => $providerId,
            ])->one();

            if (! $record) {
                throw new \Exception("Provider with ID: {$providerId} not found.");
            }
        } else {
            $record = new $recordClass();
            /**
             * enabled is default
             */
            $record->enabled = true;
        }

        /**
         * Populate some vars
         */
        $record->metadata = $metadata;
        if (is_array($mapping)) {
            $record->setMapping(
                $mapping
            );
        }
        $record->providerType = $providerType;
        $record->nameIdOverride = $nameIdOverride;

        // IDP Plugin on SP Provider ONLY
        if ($this->getPlugin()->getMyType() === SettingsInterface::IDP
            &&
            $providerType === SettingsInterface::SP
        ) {
            // Encryption settings
            $record->encryptAssertions = Craft::$app->request->getParam('encryptAssertions') ?: 0;
            $record->encryptionMethod = Craft::$app->request->getParam('encryptionMethod');
            $record->setGroupOptions(
                $groupOptions = new GroupOptions([
                    'options' => Craft::$app->request->getParam('groupOptions', []) ?: [],
                ])
            );
        }

        // Group properties
        $record->syncGroups = Craft::$app->request->getParam('syncGroups') ?: 0;
        $record->groupsAttributeName = Craft::$app->request->getParam('groupsAttributeName') ?: AbstractProvider::DEFAULT_GROUPS_ATTRIBUTE_NAME;

        /**
         * check for label and add error if it's empty
         */
        if ($label) {
            $record->label = $label;
        } else {
            $record->addError('label', Craft::t($plugin->getHandle(), "Label is required."));
        }


        if ($keyId) {
            /** @var KeyChainRecord $keychain */
            if ($keychain = KeyChainRecord::find()->where([
                'id' => $keyId,
            ])->one()) {
                $record->setKeychain(
                    $keychain
                );
            }
        }

        /**
         * Metadata should exist for the remote provider
         */
        if ($plugin->getRemoteType() === $providerType && ! $metadata) {
            $record->addError('metadata', Craft::t($plugin->getHandle(), "Metadata cannot be empty."));
        }

        return $record;
    }

    /**
     * @param $keyId
     * @return static
     * @throws NotFoundHttpException
     * @throws \yii\web\ForbiddenHttpException
     * @throws \yii\web\HttpException
     */
    public function actionDownloadCertificate($keyId)
    {
        $this->requireAdmin();

        /** @var KeyChainRecord $keychain */
        if (! $keychain = KeyChainRecord::find()->where([
            'id' => $keyId,
        ])->one()) {
            throw new NotFoundHttpException('Key not found');
        }

        return Craft::$app->response->sendContentAsFile($keychain->getDecryptedCertificate(), 'certificate.crt');
    }
}
