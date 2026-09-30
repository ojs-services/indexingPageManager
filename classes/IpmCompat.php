<?php

/**
 * @file plugins/generic/indexingPageManager/classes/IpmCompat.php
 *
 * @class IpmCompat
 *
 * @brief The few places where OJS 3.4 and 3.5 differ, in one class. Each
 *  check asks what the running OJS offers — never its version number.
 */

namespace APP\plugins\generic\indexingPageManager\classes;

class IpmCompat
{
    /**
     * The session's CSRF token. OJS 3.5's session is Laravel's (token());
     * OJS 3.4's is PKP's own (getCSRFToken()).
     */
    public static function csrfToken($request): ?string
    {
        $session = $request->getSession();
        if (!$session) {
            return null;
        }
        return method_exists($session, 'token') ? $session->token() : $session->getCSRFToken();
    }
}
