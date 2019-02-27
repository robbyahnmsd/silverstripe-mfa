<?php
namespace SilverStripe\MFA;

use LogicException;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\MFA\Extensions\MemberExtension;
use SilverStripe\MFA\Model\AuthenticationMethod;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberAuthenticator\LoginHandler as BaseLoginHandler;
use SilverStripe\Security\MemberAuthenticator\MemberLoginForm;

class LoginHandler extends BaseLoginHandler
{
    const SESSION_KEY = 'MFALogin';

    private static $url_handlers = [
        'mfa/start/$Method' => 'start',
        'mfa/verify' => 'verify',
        'mfa' => 'mfa',
    ];

    private static $allowed_actions = [
        'mfa',
        'startMethod',
    ];

    /**
     * Indicate how many MFA methods the user must authenticate with before they are considered logged in
     *
     * @config
     * @var int
     */
    private static $required_mfa_methods = 1;

    /**
     * A "session store" object that helps contain MFA specific session detail
     *
     * @var SessionStore
     */
    protected $sessionStore;

    /**
     * Override the parent "doLogin" to insert extra steps into the flow
     *
     * @inheritdoc
     */
    public function doLogin($data, MemberLoginForm $form, HTTPRequest $request)
    {
        $member = $this->checkLogin($data, $request, $result);

        // If there's no member it's an invalid login. We'll delegate this to the parent
        if (!$member) {
            return parent::doLogin($data, $form, $request);
        }

        // Store a reference to the member in session
        $this->getSessionStore()->setMember($member);
        $this->getSessionStore()->save($request);

        // Store the BackURL for use after the process is complete
        if (!empty($data)) {
            $request->getSession()->set(static::SESSION_KEY . '.additionalData', $data);
        }

        // Redirect to the MFA step
        return $this->redirect($this->link('mfa'));
    }

    /**
     * Action handler for displaying the MFA authentication React app
     *
     * @return array|HTTPResponse
     */
    public function mfa()
    {
        $member = $this->getSessionStore()->getMember();

        // If we don't have a valid member we shouldn't be here...
        if (!$member) {
            return $this->redirectBack();
        }

        // Get a list of authentication for the user and the find default
        $authMethods = $member->AuthenticationMethods();

        // Pool a list of "lead in" labels. We skip the default here assuming it's not required.
        $alternateLeadInLabels = [];
        foreach ($authMethods as $method) {
            $alternateLeadInLabels[str_replace('\\', '-', get_class($method))] =
                $method->getAuthenticator()->getLeadInLabel();
        }

        return [
            'methods' => $alternateLeadInLabels,
        ];
    }

    /**
     * Handles the request to start an authentication process with an authenticator (possibly specified by the request)
     *
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function start(HTTPRequest $request)
    {
        $sessionStore = $this->getSessionStore();
        $member = $sessionStore->getMember();

        // If we don't have a valid member we shouldn't be here...
        if (!$member) {
            return $this->redirectBack();
        }

        // Pull a method to use from the request or use the default
        $specifiedMethod = str_replace('-', '\\', $request->param('Method')) ?: $member->DefaultAuthenticationMethod;
        list($method, $candidate) = $this->getMethodFromMember($member, $specifiedMethod);

        // Mark the given method as started within the session
        $sessionStore->setMethod($candidate->MethodClassName);
        // Allow the authenticator to begin the process and generate some data to pass through to the front end
        $data = $method->getAuthenticator()->start($sessionStore);
        // Ensure detail is saved to the session
        $sessionStore->save($request);

        // Respond with our method
        return $this->jsonResponse($data);
    }

    public function verify(HTTPRequest $request)
    {
        $method = $this->getSessionStore()->getMethod();

        // We must've been to a "start" and set the method being used in session here.
        if (!$method) {
            return $this->redirectBack();
        }

        // Get the member and authenticator ready
        $member = $this->getSessionStore()->getMember();
        $authenticator = $this->getMethodFromMember($member, $method)->getAuthenticator();

        if (!$authenticator->verify($request, $this->getSessionStore())) {
            // TODO figure out how to return a message here too.
            return $this->redirect($this->link('mfa'));
        }

        $this->addSuccessfulVerification($request, $method);

        if (!$this->isLoginComplete($request)) {
            return $this->redirect($this->link('mfa'));
        }

        // Load the previously stored data from session and perform the login using it...
        $data = $request->getSession()->get(static::SESSION_KEY . '.additionalData');
        $this->performLogin($member, $data, $request);

        // Clear session...
        SessionStore::clear($request);
        $request->getSession()->clear(static::SESSION_KEY . '.additionalData');
        $request->getSession()->clear(static::SESSION_KEY . '.successfulMethods');

        // Redirecting after successful login expects a getVar to be set
        if (!empty($data['BackURL'])) {
            $request->BackURL = $data['BackURL'];
        }
        return $this->redirectAfterSuccessfulLogin();
    }

    /**
     * Respond with the given array as a JSON response
     *
     * @param array $response
     * @return HTTPResponse
     */
    protected function jsonResponse(array $response)
    {
        return HTTPResponse::create(json_encode($response))->addHeader('Content-Type', 'application/json');
    }

    /**
     * Indicate that the user has successfully verifed the given authentication method
     *
     * @param string $method The method class name
     */
    protected function addSuccessfulVerification(HTTPRequest $request, $method)
    {
        // Pull the prior sucesses from the session
        $key = static::SESSION_KEY . '.successfulMethods';
        $successfulMethods = $request->getSession()->get($key);

        // Coalesce these methods
        if (!$successfulMethods) {
            $successfulMethods = [];
        }

        // Add our new success
        $successfulMethods[] = $method;

        // Ensure it's persisted in session
        $request->getSession()->set($key, $successfulMethods);

        return $this;
    }

    protected function isLoginComplete(HTTPRequest $request)
    {
        // Pull the successful methods from session
        $successfulMethods = $request->getSession()->get(static::SESSION_KEY . '.successfulMethods');

        // Zero is "not complete". There's different config for optional MFA
        if (!is_array($successfulMethods) || !count($successfulMethods)) {
            return false;
        }

        return count($successfulMethods) >= static::config()->get('required_mfa_methods');
    }

    /**
     * @return SessionStore
     */
    protected function getSessionStore()
    {
        if (!$this->sessionStore) {
            $this->sessionStore = SessionStore::create($this->getRequest());
        }

        return $this->sessionStore;
    }

    /**
     * Get an authentication method object matching the given method from the given member.
     *
     * @param Member|MemberExtension $member
     * @param string $specifiedMethod
     * @return AuthenticationMethodInterface
     */
    protected function getMethodFromMember(Member $member, $specifiedMethod)
    {
        $method = null;

        // Find the actual method registration data object from the member for the specified default authenticator
        foreach ($member->AuthenticationMethods() as $candidate) {
            if ($candidate->MethodClassName === $specifiedMethod) {
                $method = $candidate;
                break;
            }
        }

        // In this scenario the member has managed to set a default authenticator that has no registration.
        if (!$method) {
            throw new LogicException(sprintf(
                'There is no authenticator registered for this member that matches the requested method ("%s")',
                $specifiedMethod
            ));
        }

        return $method;
    }
}