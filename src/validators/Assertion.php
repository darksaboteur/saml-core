<?php


namespace flipbox\saml\core\validators;

use flipbox\saml\core\AbstractPlugin;
use flipbox\saml\core\helpers\SecurityHelper;
use flipbox\saml\core\records\AbstractProvider;
use SAML2\Assertion as SamlAssertion;
use SAML2\Assertion\Validation\AssertionConstraintValidator;
use SAML2\Assertion\Validation\ConstraintValidator;
use SAML2\Assertion\Validation\Result;
use SAML2\Assertion\Validation\SubjectConfirmationConstraintValidator;
use SAML2\Configuration\Destination;
use SAML2\EncryptedAssertion;

class Assertion
{
    /**
     * @var AbstractProvider
     */
    private $identityProvider;
    /**
     * @var AbstractProvider
     */
    private $serviceProvider;
    /**
     * @var \SAML2\Response
     */
    private $response;
    private $validators;

    /**
     * Assertion constructor.
     * @param \SAML2\Response $response
     * @param AbstractProvider $identityProvider
     * @param AbstractProvider $serviceProvider
     * @throws \Exception
     */
    public function __construct(
        \SAML2\Response $response,
        AbstractProvider $identityProvider,
        AbstractProvider $serviceProvider
    ) {
        $this->identityProvider = $identityProvider;
        $this->serviceProvider = $serviceProvider;
        $this->response = $response;

        $this->addValidators();
    }

    /**
     * @throws \Exception
     */
    private function addValidators()
    {
        $this->validators = [
            new ConstraintValidator\NotBefore(),
            new ConstraintValidator\NotOnOrAfter(),
            new ConstraintValidator\SessionNotOnOrAfter(),
            new ConstraintValidator\SubjectConfirmationMethod(),
            new ConstraintValidator\SubjectConfirmationNotBefore(),
            new ConstraintValidator\SubjectConfirmationNotOnOrAfter(),
            new ConstraintValidator\SubjectConfirmationRecipientMatches(
                new Destination(
                    $this->serviceProvider->firstSpAcsService()->getLocation()
                )
            ),
            new ConstraintValidator\SubjectConfirmationResponseToMatches(
                $this->response
            ),

        ];
        if ($key = $this->identityProvider->signingXMLSecurityKey()) {
            $this->validators[] = new SignedElement($key);
        }
    }

    /**
     * @param $assertion
     * @return Result
     */
    public function validate($assertion): Result
    {
        // Decrypt if needed
        if ($assertion instanceof EncryptedAssertion) {
            $assertion = SecurityHelper::decryptAssertion(
                $assertion,
                $this->serviceProvider->keychain->getDecryptedCertificate()
            );
        }

        /** @var SamlAssertion $assertion */

        $result = new Result();

        foreach ($this->validators as $validator) {
            if ($validator instanceof SubjectConfirmationConstraintValidator) {
                $this->validateSubjectConfirmations(
                    $validator,
                    $assertion->getSubjectConfirmation(),
                    $result
                );
            } else {

                /** @var SignedElement|AssertionConstraintValidator $validator */
                $validator->validate($assertion, $result);
            }
            \Craft::debug(
                sprintf(
                    "%s validation errors: %s",
                    \get_class($validator),
                    \json_encode($result->getErrors())
                ),
                AbstractPlugin::SAML_CORE_HANDLE
            );
        }

        return $result;
    }

    /**
     * @param array $subjectConfirmations
     * @param Result $result
     */
    protected function validateSubjectConfirmations(
        SubjectConfirmationConstraintValidator $validator,
        array $subjectConfirmations,
        Result $result
    ) {
        foreach ($subjectConfirmations as $subjectConfirmation) {
            $validator->validate($subjectConfirmation, $result);
        }
    }
}
