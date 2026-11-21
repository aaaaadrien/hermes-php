<?php
/**
 * OIDC error
 * ===========================
 * Exception of the OIDC/SSO flow: the message is meant for the user and
 * can be displayed as-is in the interface.
 */

declare(strict_types=1);

namespace Hermes;

class OidcException extends \RuntimeException
{
}
